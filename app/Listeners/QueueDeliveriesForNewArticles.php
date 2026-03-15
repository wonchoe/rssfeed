<?php

namespace App\Listeners;

use App\Events\NewArticlesDetected;
use App\Jobs\QueueTelegramDeliveriesJob;
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
        QueueTelegramDeliveriesJob::dispatch(
            sourceId: $event->sourceId,
            articleCount: $event->newArticleCount,
            context: $event->context,
        )->onQueue('delivery');
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
