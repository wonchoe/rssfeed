<?php

namespace App\Listeners;

use App\Events\NewArticlesDetected;
use App\Jobs\QueueTelegramDeliveriesJob;
use App\Jobs\QueueWebhookDeliveriesJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class QueueDeliveriesForNewArticles implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'delivery';

    public int $tries = 3;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(NewArticlesDetected $event): void
    {
        // Telegram (existing real-time delivery)
        QueueTelegramDeliveriesJob::dispatch(
            sourceId: $event->sourceId,
            articleCount: $event->newArticleCount,
            context: $event->context,
        )->onQueue('delivery');

        // Slack, Discord, Teams (webhook-based real-time delivery)
        foreach (['slack', 'discord', 'teams'] as $channel) {
            QueueWebhookDeliveriesJob::dispatch(
                sourceId: $event->sourceId,
                channel: $channel,
                articleCount: $event->newArticleCount,
                context: $event->context,
            )->onQueue('delivery');
        }

        // Email is handled by the daily scheduled digest — not dispatched per-event
    }

    /**
     * Calculate retry backoff in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 45, 120];
    }
}
