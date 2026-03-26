<?php

namespace App\Jobs;

use App\Events\DeliveryRequested;
use App\Models\Article;
use App\Models\Delivery;
use App\Models\Subscription;
use App\Support\PipelineStage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueueWebhookDeliveriesJob implements ShouldQueue
{
    use Queueable;

    private const TRANSLATABLE_CHANNELS = ['teams'];

    public int $tries = 4;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $channel,
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
            ->where('channel', $this->channel)
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
        $translationBatch = []; // keyed by "{channel}-{articleId}-{language}"

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
                        'channel' => $this->channel,
                    ],
                    [
                        'status' => 'queued',
                        'attempts' => 0,
                        'queued_at' => now(),
                        'meta' => ['pipeline_stage' => PipelineStage::Deliver->value],
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

                if (
                    in_array($this->channel, self::TRANSLATABLE_CHANNELS, true)
                    && $subscription->translate_enabled
                    && $subscription->translate_language
                ) {
                    $batchKey = $this->channel.'-'.$article->id.'-'.$subscription->translate_language;

                    if (! isset($translationBatch[$batchKey])) {
                        $translationBatch[$batchKey] = [
                            'channel' => $this->channel,
                            'article_id' => $article->id,
                            'language' => $subscription->translate_language,
                            'recipients' => [],
                        ];
                    }

                    $translationBatch[$batchKey]['recipients'][] = [
                        'subscription_id' => $subscription->id,
                        'delivery_id' => $delivery->id,
                        'channel' => $this->channel,
                    ];
                } else {
                    $this->dispatchChannelMessage(
                        subscriptionId: (string) $subscription->id,
                        channel: $this->channel,
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
                    );
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
            channel: $this->channel,
            articleCount: $queuedCount,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Deliver->value,
                'queued_messages' => $queuedCount,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatchChannelMessage(
        string $subscriptionId,
        string $channel,
        string $articleUrl,
        string $message,
        string $summary,
        ?string $imageUrl,
        array $context,
    ): void {
        $jobClass = match ($channel) {
            'slack' => SendSlackMessageJob::class,
            'discord' => SendDiscordMessageJob::class,
            'teams' => SendTeamsMessageJob::class,
            default => null,
        };

        if ($jobClass === null) {
            return;
        }

        $jobClass::dispatch(
            subscriptionId: $subscriptionId,
            articleUrl: $articleUrl,
            message: $message,
            summary: $summary,
            imageUrl: $imageUrl,
            context: $context,
        )->onQueue('delivery');
    }
}
