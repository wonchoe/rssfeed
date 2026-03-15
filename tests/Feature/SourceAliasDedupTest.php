<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceAliasDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_store_reuses_same_source_for_tracking_url_variants(): void
    {
        Queue::fake();
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post('/subscriptions', [
            'source_url' => 'https://github.blog/?utm_source=test',
            'channel' => 'telegram',
            'target' => '@demo_channel_a',
            'polling_interval_minutes' => 30,
        ])->assertRedirect('/dashboard');

        $this->actingAs($user)->post('/subscriptions', [
            'source_url' => 'https://github.blog/#top',
            'channel' => 'telegram',
            'target' => '@demo_channel_b',
            'polling_interval_minutes' => 30,
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseCount('sources', 1);
        $this->assertDatabaseCount('source_aliases', 1);

        $source = Source::query()->firstOrFail();
        $this->assertSame('https://github.blog/', $source->source_url);
    }
}
