<?php

namespace App\Listeners;

use App\Events\SourceFetched;
use App\Jobs\ParseArticlesJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RequestSourceParse implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'ingestion';

    public int $tries = 3;

    public function handle(SourceFetched $event): void
    {
        if ($event->statusCode !== 200 || $event->contentHash === null) {
            return;
        }

        ParseArticlesJob::dispatch(
            sourceId: $event->sourceId,
            context: $event->context,
        )->onQueue('ingestion');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 45, 90];
    }
}
