<?php

namespace Tests\Feature;

use App\Domain\Parsing\Contracts\AiSchemaResolver;
use App\Models\Article;
use App\Models\FeedGeneration;
use App\Models\Source;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class FeedGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ingestion.preview_image_enrichment.enabled', false);
    }

    public function test_guest_is_redirected_from_generator_page(): void
    {
        $this->get('/feeds/new')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_generator_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/feeds/new');

        $response->assertOk();
        $response->assertSee('Create New Feed');
        $response->assertSee('Generate Preview');
        $response->assertSee('Add This Feed');
        $response->assertSee('Add This Feed');
    }

    public function test_generator_preview_returns_parsed_feed_items(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Example Feed</title>
    <item>
      <title>Breaking A</title>
      <link>https://example.com/a</link>
      <description>Summary A</description>
      <pubDate>Sat, 14 Mar 2026 15:00:00 GMT</pubDate>
    </item>
    <item>
      <title>Breaking B</title>
      <link>https://example.com/b</link>
      <description>Summary B</description>
      <pubDate>Sat, 14 Mar 2026 15:05:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $user = User::factory()->create();

        $generateResponse = $this->actingAs($user)->postJson('/feeds/generate', [
            'source_url' => 'https://example.com/feed.xml',
        ]);

        $generateResponse->assertOk();
        $generateResponse->assertJsonPath('ok', true);

        $generationId = $generateResponse->json('generation_id');

        $statusResponse = $this->actingAs($user)->getJson('/feeds/generate/'.$generationId);

        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('ok', true);
        $statusResponse->assertJsonPath('status', 'ready');
        $statusResponse->assertJsonPath('source.source_type', 'rss');
        $statusResponse->assertJsonPath('source.resolved_url', 'https://example.com/feed.xml');
        $statusResponse->assertJsonCount(2, 'preview');
        $statusResponse->assertJsonPath('preview.0.title', 'Breaking A');
    }

    public function test_generator_uses_ai_fallback_for_html_source_and_saves_schema(): void
    {
        config()->set('ingestion.discovery.feed_probe_enabled', false);
        config()->set('ingestion.ai_preview_min_items', 1);

        Http::fake([
            'https://example-news.test/' => Http::response(
                <<<'HTML'
<!doctype html>
<html>
  <body>
    <main>
      <article>
        <h2>TSN Headline 1</h2>
        <a href="/news/alpha">Read</a>
        <p>TSN Summary 1</p>
        <time datetime="2026-03-14T12:00:00+00:00">14 березня</time>
      </article>
      <article>
        <h2>TSN Headline 2</h2>
        <a href="/news/beta">Read</a>
        <p>TSN Summary 2</p>
        <time datetime="2026-03-14T13:00:00+00:00">14 березня</time>
      </article>
    </main>
  </body>
</html>
HTML,
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $this->app->bind(AiSchemaResolver::class, fn () => new class implements AiSchemaResolver
        {
            public function resolve(string $html, string $sourceUrl): array
            {
                return [
                    'valid' => true,
                    'strategy' => 'ai_generated_xpath',
                    'confidence' => 0.92,
                    'article_xpath' => '//article[.//a[@href]]',
                    'title_xpath' => './/h2',
                    'link_xpath' => './/a[@href][1]/@href',
                    'summary_xpath' => './/p[1]',
                    'image_xpath' => '',
                    'date_xpath' => './/time/@datetime',
                ];
            }
        });

        $user = User::factory()->create();

        $generateResponse = $this->actingAs($user)->postJson('/feeds/generate', [
            'source_url' => 'https://example-news.test/',
        ]);

        $generateResponse->assertOk();
        $generationId = (string) $generateResponse->json('generation_id');

        $statusResponse = $this->actingAs($user)->getJson('/feeds/generate/'.$generationId);
        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('status', 'ready');
        $statusResponse->assertJsonPath('preview.0.title', 'TSN Headline 1');
        $statusResponse->assertJsonPath('preview.0.url', 'https://example-news.test/news/alpha');
        $statusResponse->assertJsonPath('preview.0.summary', 'TSN Summary 1');

        $generation = FeedGeneration::query()->findOrFail($generationId);
        $source = Source::query()->findOrFail($generation->source_id);

        $this->assertSame('parsed_with_ai_schema', data_get($generation->meta, 'ai_preview.status'));
        $this->assertTrue($source->parserSchemas()->where('strategy_type', 'ai_xpath_schema')->where('is_active', true)->exists());
    }

    public function test_user_cannot_access_other_users_generation_status(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                '<rss version="2.0"><channel></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $generateResponse = $this->actingAs($owner)->postJson('/feeds/generate', [
            'source_url' => 'https://example.com/feed.xml',
        ]);

        $generationId = $generateResponse->json('generation_id');

        $this->actingAs($intruder)
            ->getJson('/feeds/generate/'.$generationId)
            ->assertForbidden();
    }

    public function test_owner_can_open_generation_stream_endpoint(): void
    {
        $owner = User::factory()->create();

        $generation = FeedGeneration::query()->create([
            'user_id' => $owner->id,
            'requested_url' => 'https://example.com/feed.xml',
            'resolved_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'ready',
            'message' => 'Preview generated successfully.',
            'preview_items' => [
                [
                    'title' => 'Demo',
                    'url' => 'https://example.com/demo',
                    'summary' => 'Demo summary',
                    'published_at' => '2026-03-14T12:00:00+00:00',
                ],
            ],
        ]);

        $response = $this->actingAs($owner)->get('/feeds/generate/'.$generation->id.'/stream');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $this->assertStringContainsString('event: done', $response->streamedContent());
    }

    public function test_generation_status_sanitizes_preview_payload(): void
    {
        $owner = User::factory()->create();

        $generation = FeedGeneration::query()->create([
            'user_id' => $owner->id,
            'requested_url' => 'https://example.com/feed.xml',
            'status' => 'ready',
            'message' => 'Preview generated successfully.',
            'preview_items' => [
                [
                    'title' => '<h2>Demo &amp; Test</h2>',
                    'url' => 'https://example.com/post?utm_source=test',
                    'summary' => '<p>Hello <strong>world</strong></p>',
                    'image' => 'https://cdn.example.com/demo.jpg',
                    'published_at' => '2026-03-05 17:00:00',
                ],
            ],
        ]);

        $response = $this->actingAs($owner)->getJson('/feeds/generate/'.$generation->id);

        $response->assertOk();
        $response->assertJsonPath('preview.0.title', 'Demo & Test');
        $response->assertJsonPath('preview.0.url', 'https://example.com/post');
        $response->assertJsonPath('preview.0.summary', 'Hello world');
        $response->assertJsonPath('preview.0.image_url', 'https://cdn.example.com/demo.jpg');
        $response->assertJsonPath('preview.0.published_at', '2026-03-05T17:00:00+00:00');
    }

    public function test_user_cannot_access_other_users_generation_stream(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $generation = FeedGeneration::query()->create([
            'user_id' => $owner->id,
            'requested_url' => 'https://example.com/feed.xml',
            'status' => 'queued',
            'message' => 'Queued for generation.',
        ]);

        $this->actingAs($intruder)
            ->get('/feeds/generate/'.$generation->id.'/stream')
            ->assertForbidden();
    }

    public function test_generate_throttle_returns_clean_json_without_stack_trace(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                '<rss version="2.0"><channel><item><title>Ok</title><link>https://example.com/a</link></item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        RateLimiter::for('feed-generate', function (Request $request): Limit {
            return Limit::perMinute(1)->by((string) ($request->user()?->id ?? 'guest'));
        });

        $this->actingAs($user)
            ->postJson('/feeds/generate', ['source_url' => 'https://example.com/feed.xml'])
            ->assertOk();

        $throttled = $this->actingAs($user)
            ->postJson('/feeds/generate', ['source_url' => 'https://example.com/feed.xml']);

        $throttled->assertStatus(429);
        $throttled->assertJsonPath('error', 'too_many_attempts');
        $throttled->assertJsonMissingPath('exception');
    }

    public function test_stale_generation_is_auto_escalated_to_failed_status(): void
    {
        config()->set('ingestion.feed_generation_timeout_seconds', 30);

        $user = User::factory()->create();

        $generation = FeedGeneration::query()->create([
            'user_id' => $user->id,
            'requested_url' => 'https://github.blog/',
            'status' => 'queued',
            'message' => 'Queued for generation.',
        ]);

        FeedGeneration::query()
            ->whereKey($generation->id)
            ->update([
                'created_at' => now()->subMinutes(4),
                'updated_at' => now()->subMinutes(4),
            ]);

        $response = $this->actingAs($user)->getJson('/feeds/generate/'.$generation->id);

        $response->assertOk();
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('debug.timed_out', true);
        $response->assertJsonPath('debug.escalated', true);
    }

    public function test_generator_uses_fresh_cached_source_data_without_refetching(): void
    {
        Http::fake();

        $user = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog/'),
            'source_url' => 'https://github.blog/',
            'canonical_url_hash' => hash('sha256', 'https://github.blog/feed'),
            'canonical_url' => 'https://github.blog/feed',
            'source_type' => 'rss',
            'status' => 'active',
            'usage_state' => 'inactive',
            'health_score' => 82,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
            'last_success_at' => now()->subMinutes(5),
        ]);

        Article::query()->create([
            'source_id' => $source->id,
            'external_id' => 'demo-1',
            'canonical_url_hash' => hash('sha256', 'https://github.blog/post-1'),
            'canonical_url' => 'https://github.blog/post-1',
            'content_hash' => hash('sha256', 'post-1'),
            'title' => 'Cached Post 1',
            'summary' => 'Summary 1',
            'published_at' => now()->subMinutes(3),
            'discovered_at' => now()->subMinutes(3),
        ]);

        Article::query()->create([
            'source_id' => $source->id,
            'external_id' => 'demo-2',
            'canonical_url_hash' => hash('sha256', 'https://github.blog/post-2'),
            'canonical_url' => 'https://github.blog/post-2',
            'content_hash' => hash('sha256', 'post-2'),
            'title' => 'Cached Post 2',
            'summary' => 'Summary 2',
            'published_at' => now()->subMinutes(2),
            'discovered_at' => now()->subMinutes(2),
        ]);

        $generateResponse = $this->actingAs($user)->postJson('/feeds/generate', [
            'source_url' => 'https://github.blog/?utm_source=test',
        ]);

        $generateResponse->assertOk();

        $statusResponse = $this->actingAs($user)->getJson('/feeds/generate/'.$generateResponse->json('generation_id'));
        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('status', 'ready');
        $statusResponse->assertJsonPath('source.source_id', $source->id);
        $statusResponse->assertJsonPath('source.cache_mode', 'fresh');
        $statusResponse->assertJsonPath('preview.0.title', 'Cached Post 2');

        Http::assertNothingSent();
    }

    public function test_generator_reparses_when_cached_source_is_stale(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Fresh Post</title>
      <link>https://example.com/fresh</link>
      <description>Fresh Summary</description>
      <pubDate>Sat, 14 Mar 2026 19:15:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $user = User::factory()->create();

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'source_url' => 'https://example.com/feed.xml',
            'canonical_url_hash' => hash('sha256', 'https://example.com/feed.xml'),
            'canonical_url' => 'https://example.com/feed.xml',
            'source_type' => 'rss',
            'status' => 'active',
            'usage_state' => 'inactive',
            'health_score' => 70,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
            'last_success_at' => now()->subHours(4),
        ]);

        Article::query()->create([
            'source_id' => $source->id,
            'external_id' => 'old-1',
            'canonical_url_hash' => hash('sha256', 'https://example.com/old'),
            'canonical_url' => 'https://example.com/old',
            'content_hash' => hash('sha256', 'old'),
            'title' => 'Old Cached Post',
            'summary' => 'Old summary',
            'published_at' => now()->subHours(5),
            'discovered_at' => now()->subHours(5),
        ]);

        $generateResponse = $this->actingAs($user)->postJson('/feeds/generate', [
            'source_url' => 'https://example.com/feed.xml',
        ]);

        $generateResponse->assertOk();

        $statusResponse = $this->actingAs($user)->getJson('/feeds/generate/'.$generateResponse->json('generation_id'));
        $statusResponse->assertOk();
        $statusResponse->assertJsonPath('status', 'ready');
        $statusResponse->assertJsonPath('source.cache_mode', 'refreshed_live');
        $statusResponse->assertJsonPath('preview.0.title', 'Fresh Post');
        $statusResponse->assertJsonPath('preview.0.url', 'https://example.com/fresh');

        Http::assertSentCount(2);
    }
}
