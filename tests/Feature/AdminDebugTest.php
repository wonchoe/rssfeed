<?php

namespace Tests\Feature;

use App\Jobs\DiscoverSourceTypeJob;
use App\Jobs\GenerateFeedPreviewJob;
use App\Models\FeedGeneration;
use App\Models\ParseAttempt;
use App\Models\Source;
use App\Models\SourceSnapshot;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_user_cannot_access_admin_debug_console(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/debug/generations')
            ->assertForbidden();
    }

    public function test_configured_admin_user_can_open_debug_console(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        config()->set('admin.allowed_emails', ['admin@example.com']);

        $response = $this->actingAs($admin)->get('/admin/debug/generations');

        $response->assertOk();
        $response->assertSee('Admin Debug Console');
        $response->assertSee('Queue Snapshot');
    }

    public function test_admin_can_retry_generation_from_debug_console(): void
    {
        Queue::fake();

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        config()->set('admin.allowed_emails', ['admin@example.com']);

        $generation = FeedGeneration::query()->create([
            'user_id' => $admin->id,
            'requested_url' => 'https://github.blog/',
            'status' => 'failed',
            'message' => 'Generation timed out.',
            'meta' => [
                'timed_out' => true,
                'escalated_to_admin_debug' => true,
            ],
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->post('/admin/debug/generations/'.$generation->id.'/retry');

        $response->assertRedirect('/admin/debug/generations');

        $this->assertDatabaseHas('feed_generations', [
            'id' => $generation->id,
            'status' => 'queued',
            'message' => 'Queued for generation (admin retry).',
        ]);

        Queue::assertPushed(GenerateFeedPreviewJob::class, function (GenerateFeedPreviewJob $job) use ($generation): bool {
            return $job->generationId === (string) $generation->id;
        });
    }

    public function test_debug_console_shows_source_diagnostics_reason(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        config()->set('admin.allowed_emails', ['admin@example.com']);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog/'),
            'source_url' => 'https://github.blog/',
            'source_type' => 'unknown',
            'status' => 'pending',
            'polling_interval_minutes' => 30,
        ]);

        Subscription::query()->create([
            'user_id' => $admin->id,
            'source_id' => $source->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', '@debug_channel'),
            'target' => '@debug_channel',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get('/admin/debug/generations');

        $response->assertOk();
        $response->assertSee('Source Diagnostics');
        $response->assertSee('https://github.blog/');
        $response->assertSee('No active Horizon supervisors. Queue jobs are not being processed.');
    }

    public function test_admin_can_retry_source_from_debug_console(): void
    {
        Queue::fake();

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        config()->set('admin.allowed_emails', ['admin@example.com']);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog/'),
            'source_url' => 'https://github.blog/',
            'source_type' => 'unknown',
            'status' => 'pending',
            'polling_interval_minutes' => 30,
        ]);

        $response = $this->actingAs($admin)
            ->post('/admin/debug/sources/'.$source->id.'/retry');

        $response->assertRedirect('/admin/debug/generations');

        Queue::assertPushed(DiscoverSourceTypeJob::class, function (DiscoverSourceTypeJob $job) use ($source): bool {
            return $job->sourceId === (string) $source->id;
        });
    }

    public function test_admin_can_open_source_snapshot_page(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        config()->set('admin.allowed_emails', ['admin@example.com']);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog/'),
            'source_url' => 'https://github.blog/',
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
        ]);

        $attempt = ParseAttempt::query()->create([
            'source_id' => $source->id,
            'stage' => 'parse',
            'status' => 'failed',
            'error_type' => 'parse_empty',
            'error_message' => 'No items found',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        SourceSnapshot::query()->create([
            'source_id' => $source->id,
            'parse_attempt_id' => $attempt->id,
            'snapshot_kind' => 'parse_input',
            'html_snapshot' => '<html><body>Snapshot</body></html>',
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/debug/sources/'.$source->id.'/snapshot');

        $response->assertOk();
        $response->assertSee('Latest Parse Attempt');
        $response->assertSee('Snapshot');
    }
}
