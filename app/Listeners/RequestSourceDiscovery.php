<?php

namespace App\Listeners;

use App\Events\SourceCreated;
use App\Jobs\DiscoverSourceTypeJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RequestSourceDiscovery implements ShouldQueue
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
    public function handle(SourceCreated $event): void
    {
        DiscoverSourceTypeJob::dispatch(
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
        return [10, 30, 60];
    }
}
