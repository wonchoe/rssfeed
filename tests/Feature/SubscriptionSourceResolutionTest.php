<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceAlias;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubscriptionSourceResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_creation_does_not_reuse_alias_matched_feed_source_for_explicit_page_url(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $existingSource = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog/feed'),
            'source_url' => 'https://github.blog/feed',
            'canonical_url_hash' => hash('sha256', 'https://github.blog/feed'),
            'canonical_url' => 'https://github.blog/feed',
            'source_type' => 'rss',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 100,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
        ]);

        SourceAlias::query()->create([
            'source_id' => $existingSource->id,
            'alias_url' => 'https://github.blog/changelog',
            'normalized_alias_url' => 'https://github.blog/changelog',
            'normalized_alias_hash' => hash('sha256', 'https://github.blog/changelog'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->post('/subscriptions', [
            'source_url' => 'https://github.blog/changelog',
            'channel' => 'email',
            'target' => 'alerts@example.com',
            'polling_interval_minutes' => 30,
        ]);

        $response->assertRedirect('/dashboard');

        $subscription = Subscription::query()->sole();

        $this->assertNotSame($existingSource->id, $subscription->source_id);
        $this->assertSame('https://github.blog/changelog', $subscription->source->source_url);
        $this->assertDatabaseCount('sources', 2);
    }
}