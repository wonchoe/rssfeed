<?php

namespace App\Jobs;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\TelegramDeliveryService;
use App\Events\DeliveryFailed;
use App\Events\DeliverySucceeded;
use App\Models\Delivery;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendTelegramMessageJob implements ShouldQueue
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

    /**
     * Execute the job.
     */
    public function handle(TelegramDeliveryService $telegramDeliveryService): void
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

        // Idempotency guard: if already delivered (e.g. job retried after a DB hiccup post-send), skip.
        if ($delivery !== null && $delivery->status === 'delivered') {
            return;
        }

        try {
            $telegramDeliveryService->send(new DeliveryMessageData(
                channel: 'telegram',
                target: $subscription->target,
                title: $this->message,
                body: $this->summary,
                url: $this->articleUrl,
                imageUrl: $this->imageUrl,
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

            $subscription->update([
                'last_delivered_at' => now(),
            ]);

            DeliverySucceeded::dispatch(
                sourceId: (string) ($this->context['source_id'] ?? $subscription->source_id),
                channel: 'telegram',
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
                channel: 'telegram',
                reason: $exception->getMessage(),
                context: $this->context,
            );

            throw $exception;
        }
    }
}
