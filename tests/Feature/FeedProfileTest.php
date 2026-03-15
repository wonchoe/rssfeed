<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_feed_profile(): void
    {
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
        ]);

        $this->get('/feeds/'.$source->id)->assertRedirect('/login');
    }

    public function test_user_can_open_feed_profile_if_subscribed(): void
    {
        $user = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'source_id' => $source->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', '@demo_channel'),
            'target' => '@demo_channel',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/feeds/'.$source->id);

        $response->assertOk();
        $response->assertSee('Feed Profile');
        $response->assertSee('Source Details');
        $response->assertSee('https://example.com/feed.xml');
    }

    public function test_user_gets_forbidden_for_unowned_feed_profile(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
        ]);

        Subscription::query()->create([
            'user_id' => $owner->id,
            'source_id' => $source->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', '@owner_channel'),
            'target' => '@owner_channel',
            'is_active' => true,
        ]);

        $this->actingAs($intruder)
            ->get('/feeds/'.$source->id)
            ->assertForbidden();
    }

    public function test_user_can_open_feed_profile_stream_if_subscribed(): void
    {
        $user = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'source_id' => $source->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', '@demo_channel'),
            'target' => '@demo_channel',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/feeds/'.$source->id.'/stream');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $this->assertStringContainsString('event: status', $response->streamedContent());
    }

    public function test_user_gets_forbidden_for_unowned_feed_profile_stream(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
        ]);

        Subscription::query()->create([
            'user_id' => $owner->id,
            'source_id' => $source->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', '@owner_channel'),
            'target' => '@owner_channel',
            'is_active' => true,
        ]);

        $this->actingAs($intruder)
            ->get('/feeds/'.$source->id.'/stream')
            ->assertForbidden();
    }
}
