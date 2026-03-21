<?php

namespace Tests\Feature;

use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthDashboardFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_create_subscription_from_dashboard(): void
    {
        $payload = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Demo Feed</title>
    <item>
      <title>Demo Article</title>
      <link>https://example.com/articles/demo</link>
      <guid>demo-1</guid>
      <description>Demo description</description>
      <pubDate>Sat, 14 Mar 2026 10:00:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                $payload,
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $registerResponse = $this->post('/register', [
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $registerResponse->assertRedirect('/dashboard');

        $dashboardResponse = $this->get('/dashboard');
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('Subscriptions');
        $dashboardResponse->assertSee('Generate Preview');
        $dashboardResponse->assertSee('Enter news site URL');
        $dashboardResponse->assertSee('Add This Feed');
        $dashboardResponse->assertDontSeeText('Link your Telegram account once');

        $storeResponse = $this->post('/subscriptions', [
            'source_url' => 'https://example.com/feed.xml',
            'channel' => 'telegram',
            'target' => '@demo_channel',
            'polling_interval_minutes' => 30,
        ]);

        $storeResponse->assertRedirect('/dashboard');

        $this->assertDatabaseCount('sources', 1);
        $this->assertDatabaseCount('subscriptions', 1);

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame('telegram', $subscription->channel);
        $this->assertSame('@demo_channel', $subscription->target);
        $this->assertNotNull($subscription->user_id);
        $this->assertTrue($subscription->is_active);
    }
}
