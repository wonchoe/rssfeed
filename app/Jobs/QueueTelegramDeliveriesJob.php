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

        $articles = Article::query()
            ->whereIn('id', $normalizedArticleIds)
            ->get()
            ->keyBy('id');

        $queuedCount = 0;

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

                if (! $delivery->wasRecentlyCreated) {
                    $delivery->update([
                        'status' => 'queued',
                        'queued_at' => now(),
                    ]);
                }

                if ($subscription->translate_enabled && $subscription->translate_language) {
                    TranslateArticleJob::dispatch(
                        articleId: $article->id,
                        subscriptionId: $subscription->id,
                        language: $subscription->translate_language,
                        deliveryId: $delivery->id,
                    )->onQueue('translation');
                } else {
                    SendTelegramMessageJob::dispatch(
                        subscriptionId: (string) $subscription->id,
                        articleUrl: $article->canonical_url,
                        message: $article->title,
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
