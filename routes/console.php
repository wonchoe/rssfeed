<?php

use App\Jobs\PollSourcesJob;
use App\Support\FeedGenerationWatchdog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (! app()->isLocal()) {
    Schedule::command('horizon:snapshot')
        ->everyFiveMinutes()
        ->withoutOverlapping();

    Schedule::command('queue:prune-batches --hours=48')
        ->dailyAt('02:00')
        ->withoutOverlapping();

    Schedule::command('queue:prune-failed --hours=168')
        ->dailyAt('02:15')
        ->withoutOverlapping();

    Schedule::call(function (): void {
        app()->call([app(PollSourcesJob::class), 'handle']);
    })
        ->name('poll-sources-inline')
        ->everyMinute()
        ->withoutOverlapping();

    Schedule::call(function (FeedGenerationWatchdog $watchdog): void {
        $watchdog->markTimedOutBatch(300);
    })
        ->name('feed-generation-watchdog')
        ->everyMinute()
        ->withoutOverlapping();
}

Artisan::command('generations:watchdog {--limit=300}', function (FeedGenerationWatchdog $watchdog): void {
    $marked = $watchdog->markTimedOutBatch((int) $this->option('limit'));

    $this->info('Marked '.$marked.' stale feed generations as failed.');
})->purpose('Escalate stale feed generations to admin debug.');
