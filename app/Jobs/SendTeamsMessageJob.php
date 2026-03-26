<?php

namespace App\Jobs;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\TeamsDeliveryService;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\Article;
use App\Models\Delivery;
use App\Models\Subscription;
use App\Models\TranslatedArticle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendTeamsMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $articleUrl,
        public readonly string $message,
        public readonly string $summary = '',
        public readonly ?string $imageUrl = null,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 20, 60, 180, 300];
    }

    public function handle(TeamsDeliveryService $teamsDeliveryService): void
    {
        $subscription = Subscription::query()->find($this->subscriptionId);

        if ($subscription === null) {
            return;
        }

        $deliveryId = is_numeric($this->context['delivery_id'] ?? null)
            ? (int) $this->context['delivery_id']
            : null;

        $delivery = $deliveryId !== null
            ? Delivery::query()->find($deliveryId)
            : null;
        $articleId = is_numeric($this->context['article_id'] ?? null)
            ? (int) $this->context['article_id']
            : null;
        $article = $articleId !== null
            ? Article::query()->find($articleId)
            : null;
        $translatedArticleId = is_numeric($this->context['translated_article_id'] ?? null)
            ? (int) $this->context['translated_article_id']
            : null;
        $translatedArticle = $translatedArticleId !== null
            ? TranslatedArticle::query()->find($translatedArticleId)
            : null;

        try {
            $teamsDeliveryService->send(new DeliveryMessageData(
                channel: 'teams',
                target: $subscription->target,
                title: $this->message,
                body: '',
                url: $this->articleUrl,
                imageUrl: $translatedArticle?->image_url ?? $article?->image_url,
                context: $this->context,
            ));

            if ($delivery !== null) {
                $delivery->update([
                    'status' => 'delivered',
                    'attempts' => $delivery->attempts + 1,
                    'delivered_at' => now(),
                    'failed_at' => null,
                    'error_message' => null,
                ]);
            }

            $subscription->update(['last_delivered_at' => now()]);

            DeliverySucceeded::dispatch(
                sourceId: (string) ($this->context['source_id'] ?? $subscription->source_id),
                channel: 'teams',
                articleCount: 1,
                context: $this->context,
            );
        } catch (Throwable $exception) {
            if ($delivery !== null) {
                $delivery->update([
                    'status' => 'failed',
                    'attempts' => $delivery->attempts + 1,
                    'failed_at' => now(),
                    'error_message' => $exception->getMessage(),
                ]);
            }

            DeliveryFailed::dispatch(
                sourceId: (string) ($this->context['source_id'] ?? $subscription->source_id),
                channel: 'teams',
                reason: $exception->getMessage(),
                context: $this->context,
            );

            throw $exception;
        }
    }
}
