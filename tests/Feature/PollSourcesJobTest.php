<?php

namespace Tests\Feature;

use App\Jobs\DiscoverSourceTypeJob;
use App\Jobs\FetchSourceJob;
use App\Jobs\PollSourcesJob;
use App\Models\Source;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SourceUsageStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PollSourcesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_job_dispatches_fetch_for_due_active_source(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
            'last_fetched_at' => null,
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'source_id' => $source->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', '@demo_channel'),
            'target' => '@demo_channel',
            'is_active' => true,
        ]);

        (new PollSourcesJob)->handle(app(SourceUsageStateManager::class));

        Queue::assertPushed(FetchSourceJob::class, function (FetchSourceJob $job) use ($source): bool {
            return $job->sourceId === (string) $source->id;
        });
    }

    public function test_poll_job_dispatches_discovery_for_unknown_source(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://portal.example.com/'),
            'source_url' => 'https://portal.example.com/',
            'source_type' => 'unknown',
            'status' => 'pending',
            'polling_interval_minutes' => 30,
            'last_fetched_at' => null,
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'source_id' => $source->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', '@demo_channel'),
            'target' => '@demo_channel',
            'is_active' => true,
        ]);

        (new PollSourcesJob)->handle(app(SourceUsageStateManager::class));

        Queue::assertPushed(DiscoverSourceTypeJob::class, function (DiscoverSourceTypeJob $job) use ($source): bool {
            return $job->sourceId === (string) $source->id;
        });
    }

    public function test_poll_job_skips_inactive_source_without_active_bindings(): void
    {
        Queue::fake();

        Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'usage_state' => 'inactive',
            'polling_interval_minutes' => 30,
            'last_fetched_at' => now()->subDays(2),
        ]);

        (new PollSourcesJob)->handle(app(SourceUsageStateManager::class));

        Queue::assertNothingPushed();
    }
}
