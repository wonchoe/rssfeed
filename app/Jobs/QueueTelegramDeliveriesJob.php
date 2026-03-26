<?php

namespace App\Jobs;

use App\Events\DeliveryRequested;
use App\Models\Article;
use App\Models\Delivery;
use App\Models\Subscription;
use App\Support\PipelineStage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueueTelegramDeliveriesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly int $articleCount,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 180, 300];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $articleIds = $this->context['article_ids'] ?? [];

        if (! is_array($articleIds) || $articleIds === []) {
            return;
        }

        $normalizedArticleIds = array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, $articleIds),
            static fn (int $id): bool => $id > 0,
        ));

        if ($normalizedArticleIds === []) {
            return;
        }

        $subscriptions = Subscription::query()
            ->where('source_id', $this->sourceId)
            ->where('channel', 'telegram')
            ->where('is_active', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        // Only deliver the single most-recent article per batch to avoid flooding channels
        $articles = Article::query()
            ->whereIn('id', $normalizedArticleIds)
            ->orderByDesc('published_at')
            ->get()
            ->keyBy('id');

        $normalizedArticleIds = [$articles->first()->id ?? null];
        $normalizedArticleIds = array_filter($normalizedArticleIds);

        $queuedCount = 0;
        $translationBatch = []; // keyed by "{articleId}-{language}"

        foreach ($subscriptions as $subscription) {
            foreach ($normalizedArticleIds as $articleId) {
                $article = $articles->get($articleId);

                if ($article === null) {
                    continue;
                }

                $delivery = Delivery::query()->firstOrCreate(
                    [
                        'article_id' => $article->id,
                        'subscription_id' => $subscription->id,
                        'channel' => 'telegram',
                    ],
                    [
                        'status' => 'queued',
                        'attempts' => 0,
                        'queued_at' => now(),
                        'meta' => [
                            'pipeline_stage' => PipelineStage::Deliver->value,
                        ],
                    ]
                );

                if (! $delivery->wasRecentlyCreated && $delivery->status === 'delivered') {
                    continue;
                }

                // If already queued (in-flight), don't dispatch a second job — the idempotency
                // guard in SendTelegramMessageJob will handle any race on the DB side.
                if (! $delivery->wasRecentlyCreated && $delivery->status === 'queued') {
                    continue;
                }

                if (! $delivery->wasRecentlyCreated) {
                    $delivery->update([
                        'status' => 'queued',
                        'queued_at' => now(),
                    ]);
                }

                if ($subscription->translate_enabled && $subscription->translate_language) {
                    $batchKey = $article->id.'-'.$subscription->translate_language;

                    if (! isset($translationBatch[$batchKey])) {
                        $translationBatch[$batchKey] = [
                            'article_id' => $article->id,
                            'language' => $subscription->translate_language,
                            'recipients' => [],
                        ];
                    }

                    $translationBatch[$batchKey]['recipients'][] = [
                        'subscription_id' => $subscription->id,
                        'delivery_id' => $delivery->id,
                    ];
                } else {
                    SendTelegramMessageJob::dispatch(
                        subscriptionId: (string) $subscription->id,
                        articleUrl: $article->canonical_url,
                        message: $article->title,
                        summary: (string) ($article->summary ?? ''),
                        imageUrl: $article->image_url,
                        context: [
                            'delivery_id' => $delivery->id,
                            'article_id' => $article->id,
                            'source_id' => $this->sourceId,
                            'pipeline_stage' => PipelineStage::Deliver->value,
                        ],
                    )->onQueue('delivery');
                }

                $queuedCount++;
            }
        }

        foreach ($translationBatch as $batch) {
            TranslateArticleJob::dispatch(
                articleId: $batch['article_id'],
                language: $batch['language'],
                recipients: $batch['recipients'],
            )->onQueue('translation');
        }

        if ($queuedCount === 0) {
            return;
        }

        DeliveryRequested::dispatch(
            sourceId: $this->sourceId,
            channel: 'telegram',
            articleCount: $queuedCount,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Deliver->value,
                'queued_messages' => $queuedCount,
            ]),
        );
    }
}
