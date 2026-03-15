<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Models\Subscription;
use App\Models\TelegramChat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionImmediateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_telegram_subscription_immediately_sends_latest_existing_article(): void
    {
        $feedUrl = 'https://example.com/feed.xml';

        Http::fake([
            $feedUrl => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Example Feed</title>
    <item>
      <title>Latest News</title>
      <link>https://example.com/news/latest</link>
      <guid>news-latest</guid>
      <description>Latest summary</description>
      <pubDate>Sun, 15 Mar 2026 16:00:00 GMT</pubDate>
    </item>
    <item>
      <title>Older News</title>
      <link>https://example.com/news/older</link>
      <guid>news-older</guid>
      <description>Older summary</description>
      <pubDate>Sun, 15 Mar 2026 15:00:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $user = User::factory()->create();

        $telegramChat = TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-1003501486562',
            'chat_type' => 'channel',
            'title' => 'mynews',
            'bot_membership_status' => 'administrator',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', $feedUrl),
            'source_url' => $feedUrl,
            'canonical_url_hash' => hash('sha256', $feedUrl),
            'canonical_url' => $feedUrl,
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
            'last_success_at' => now()->subMinutes(20),
        ]);

        Article::query()->create([
            'source_id' => $source->id,
            'external_id' => 'news-older',
            'canonical_url_hash' => hash('sha256', 'https://example.com/news/older'),
            'canonical_url' => 'https://example.com/news/older',
            'content_hash' => hash('sha256', 'older'),
            'title' => 'Older News',
            'summary' => 'Older summary',
            'published_at' => now()->subHour(),
            'discovered_at' => now()->subHour(),
        ]);

        $latestArticle = Article::query()->create([
            'source_id' => $source->id,
            'external_id' => 'news-latest',
            'canonical_url_hash' => hash('sha256', 'https://example.com/news/latest'),
            'canonical_url' => 'https://example.com/news/latest',
            'content_hash' => hash('sha256', 'latest'),
            'title' => 'Latest News',
            'summary' => 'Latest summary',
            'published_at' => now(),
            'discovered_at' => now(),
        ]);

        $response = $this->actingAs($user)->post('/subscriptions', [
            'source_url' => $feedUrl,
            'channel' => 'telegram',
            'telegram_chat_id' => $telegramChat->id,
            'polling_interval_minutes' => 30,
        ]);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('status');

        $subscription = Subscription::query()->firstOrFail();

        $this->assertDatabaseHas('deliveries', [
            'subscription_id' => $subscription->id,
            'article_id' => $latestArticle->id,
            'channel' => 'telegram',
            'status' => 'delivered',
        ]);

        $this->assertDatabaseCount('deliveries', 1);

        $subscription->refresh();
        $this->assertNotNull($subscription->last_delivered_at);
    }
}
