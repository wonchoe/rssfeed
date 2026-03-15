<?php

namespace App\Listeners;

use App\Events\SourceDiscovered;
use App\Jobs\FetchSourceJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RequestSourceFetch implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'ingestion';

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
    public function handle(SourceDiscovered $event): void
    {
        FetchSourceJob::dispatch(
            sourceId: $event->sourceId,
            context: $event->context,
        )->onQueue('ingestion');
    }

    /**
     * Calculate retry backoff in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 45, 90];
    }
}
