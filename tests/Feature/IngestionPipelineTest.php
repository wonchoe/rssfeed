<?php

namespace Tests\Feature;

use App\Events\SourceCreated;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestionPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_rss_ingestion_pipeline_persists_articles_and_deliveries(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                $this->rssPayload(),
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/subscriptions', [
            'source_url' => 'https://example.com/feed.xml',
            'channel' => 'telegram',
            'target' => '@demo_channel',
            'polling_interval_minutes' => 30,
        ]);

        $response->assertRedirect('/dashboard');

        $this->assertDatabaseHas('sources', [
            'source_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('source_fetches', 1);
        $this->assertDatabaseCount('articles', 2);
        $this->assertDatabaseCount('deliveries', 1); // only latest article per batch is delivered
        $this->assertDatabaseCount('parser_schemas', 1);
        $this->assertDatabaseCount('parse_attempts', 3);
        $this->assertDatabaseCount('source_snapshots', 2);

        $this->assertDatabaseHas('articles', [
            'canonical_url' => 'https://example.com/news/one',
            'title' => 'First News',
        ]);
    }

    public function test_html_autodiscovery_prefers_feed_link_and_avoids_duplicate_articles(): void
    {
        Http::fake([
            'https://portal.example.com/' => Http::response(
                $this->htmlWithFeedLink(),
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://portal.example.com/feed.xml' => Http::response(
                $this->rssPayload(),
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://portal.example.com/'),
            'source_url' => 'https://portal.example.com/',
            'source_type' => 'unknown',
            'status' => 'pending',
            'polling_interval_minutes' => 30,
        ]);

        SourceCreated::dispatch(
            sourceId: (string) $source->id,
            sourceUrl: $source->source_url,
            context: ['trigger' => 'test'],
        );

        SourceCreated::dispatch(
            sourceId: (string) $source->id,
            sourceUrl: $source->source_url,
            context: ['trigger' => 'test_again'],
        );

        $this->assertDatabaseHas('sources', [
            'id' => $source->id,
            'source_type' => 'rss',
            'canonical_url' => 'https://portal.example.com/feed.xml',
        ]);

        $this->assertDatabaseCount('source_fetches', 2);
        $this->assertDatabaseCount('articles', 2);
        $this->assertDatabaseCount('parser_schemas', 1);
    }

    private function rssPayload(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Example Feed</title>
    <item>
      <title>First News</title>
      <link>https://example.com/news/one</link>
      <guid>news-1</guid>
      <description>First summary</description>
      <pubDate>Sat, 14 Mar 2026 12:00:00 GMT</pubDate>
    </item>
    <item>
      <title>Second News</title>
      <link>https://example.com/news/two</link>
      <guid>news-2</guid>
      <description>Second summary</description>
      <pubDate>Sat, 14 Mar 2026 12:05:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;
    }

    private function htmlWithFeedLink(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Portal</title>
    <link rel="alternate" type="application/rss+xml" href="/feed.xml" title="Main feed" />
</head>
<body>
    <h1>Portal</h1>
</body>
</html>
HTML;
    }
}
