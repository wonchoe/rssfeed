<?php

namespace Tests\Feature;

use App\Domain\Parsing\Contracts\FeedParserService;
use App\Domain\Parsing\Services\HeuristicHtmlPatternDetector;
use App\Jobs\NormalizeArticlesJob;
use App\Jobs\ParseArticlesJob;
use App\Jobs\ResolveSchemaWithAiJob;
use App\Jobs\ValidateSchemaJob;
use App\Models\Source;
use App\Models\SourceFetch;
use App\Support\ParseAttemptTracker;
use App\Support\ParserSchemaRegistry;
use App\Support\SourceHealthTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ParseRepairWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_empty_html_source_queues_ai_repair_after_threshold(): void
    {
        Queue::fake();

        config()->set('ingestion.ai_repair_enabled', true);
        config()->set('ingestion.ai_repair_failure_threshold', 3);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/news'),
            'source_url' => 'https://example.com/news',
            'canonical_url_hash' => hash('sha256', 'https://example.com/news'),
            'canonical_url' => 'https://example.com/news',
            'source_type' => 'html',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 45,
            'health_state' => 'degraded',
            'consecutive_failures' => 3,
            'polling_interval_minutes' => 30,
        ]);

        app(ParserSchemaRegistry::class)->activateCustomSchema(
            source: $source,
            strategyType: 'deterministic_html_schema',
            schemaPayload: [
                'article_xpath' => '//article[.//a[@href]]',
                'title_xpath' => './/h2|.//h3|.//a[1]',
                'link_xpath' => './/a[@href][1]/@href',
                'summary_xpath' => './/p[1]',
                'image_xpath' => './/img[1]/@src',
                'date_xpath' => './/time/@datetime|.//time',
            ],
            confidence: 0.8,
            createdBy: 'test',
        );

        $fetch = SourceFetch::query()->create([
            'source_id' => $source->id,
            'fetched_url_hash' => hash('sha256', 'https://example.com/news'),
            'fetched_url' => 'https://example.com/news',
            'http_status' => 200,
            'content_hash' => hash('sha256', '<html><body><div>No feed structure</div></body></html>'),
            'payload_bytes' => 64,
            'fetched_at' => now(),
            'response_headers' => [],
        ]);

        $payloadCacheKey = sprintf('ingestion:payload:%s:%s', $source->id, $fetch->content_hash);
        Cache::put($payloadCacheKey, '<html><body><div>No feed structure</div></body></html>', now()->addMinutes(30));

        $job = new ParseArticlesJob((string) $source->id, ['fetch_id' => $fetch->id]);
        $job->handle(
            app(FeedParserService::class),
            app(SourceHealthTracker::class),
            app(ParseAttemptTracker::class),
            app(ParserSchemaRegistry::class),
            app(HeuristicHtmlPatternDetector::class),
        );

        Queue::assertPushed(ResolveSchemaWithAiJob::class, function (ResolveSchemaWithAiJob $queued) use ($source): bool {
            return $queued->sourceId === (string) $source->id;
        });
        Queue::assertPushed(ValidateSchemaJob::class, function (ValidateSchemaJob $queued) use ($source): bool {
            return $queued->sourceId === (string) $source->id
                && ($queued->context['trigger'] ?? null) === 'active_schema_parse_empty';
        });
    }

    public function test_parse_empty_html_source_uses_feed_fallback_before_ai_repair(): void
    {
        Queue::fake();

        config()->set('ingestion.ai_repair_enabled', true);

        Http::fake([
            'https://example.com/news/feed.xml' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Fallback feed item</title>
      <link>https://example.com/news/fallback-item</link>
      <description>Fallback summary content that is useful.</description>
      <pubDate>Tue, 25 Mar 2026 12:00:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
            ),
            '*' => Http::response('not found', 404),
        ]);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/news'),
            'source_url' => 'https://example.com/news',
            'source_type' => 'html',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 45,
            'health_state' => 'degraded',
            'consecutive_failures' => 3,
            'polling_interval_minutes' => 30,
            'meta' => [
                'discovery' => [
                    'fallback_candidates' => [
                        [
                            'url' => 'https://example.com/news/feed.xml',
                            'type' => 'rss',
                            'confidence' => 0.87,
                            'strategy' => 'html_autodiscovery',
                        ],
                    ],
                ],
            ],
        ]);

        $fetch = SourceFetch::query()->create([
            'source_id' => $source->id,
            'fetched_url_hash' => hash('sha256', 'https://example.com/news'),
            'fetched_url' => 'https://example.com/news',
            'http_status' => 200,
            'content_hash' => hash('sha256', '<html><body><div>No feed structure</div></body></html>'),
            'payload_bytes' => 64,
            'fetched_at' => now(),
            'response_headers' => [],
        ]);

        $payloadCacheKey = sprintf('ingestion:payload:%s:%s', $source->id, $fetch->content_hash);
        Cache::put($payloadCacheKey, '<html><body><div>No feed structure</div></body></html>', now()->addMinutes(30));

        $job = new ParseArticlesJob((string) $source->id, ['fetch_id' => $fetch->id]);
        $job->handle(
            app(FeedParserService::class),
            app(SourceHealthTracker::class),
            app(ParseAttemptTracker::class),
            app(ParserSchemaRegistry::class),
            app(HeuristicHtmlPatternDetector::class),
        );

        Queue::assertNotPushed(ResolveSchemaWithAiJob::class);
        Queue::assertPushed(NormalizeArticlesJob::class, function (NormalizeArticlesJob $queued) use ($source): bool {
            return $queued->sourceId === (string) $source->id;
        });
    }

        public function test_parse_empty_html_source_recovers_with_new_deterministic_schema(): void
        {
                Queue::fake();

                $source = Source::query()->create([
                        'source_url_hash' => hash('sha256', 'https://example.com/news'),
                        'source_url' => 'https://example.com/news',
                        'canonical_url_hash' => hash('sha256', 'https://example.com/news'),
                        'canonical_url' => 'https://example.com/news',
                        'source_type' => 'html',
                        'status' => 'active',
                        'usage_state' => 'active',
                        'health_score' => 70,
                        'health_state' => 'healthy',
                        'consecutive_failures' => 1,
                        'polling_interval_minutes' => 30,
                ]);

                $oldSchema = app(ParserSchemaRegistry::class)->activateCustomSchema(
                        source: $source,
                        strategyType: 'deterministic_html_schema',
                        schemaPayload: [
                                'article_xpath' => '//article[@class="old-card"]',
                                'title_xpath' => './/h2',
                                'link_xpath' => './/a/@href',
                                'summary_xpath' => './/p[1]',
                                'image_xpath' => './/img[1]/@src',
                                'date_xpath' => './/time/@datetime',
                        ],
                        confidence: 0.7,
                        createdBy: 'test',
                );

                $payload = <<<'HTML'
<html>
    <body>
        <main>
            <article class="news-card">
                <time datetime="2026-03-25">Mar.25</time>
                <h2><a class="article-title" href="https://example.com/news/first-story">First recovered article title</a></h2>
                <p>This is the first recovered summary and it has enough content to be valid.</p>
            </article>
            <article class="news-card">
                <time datetime="2026-03-24">Mar.24</time>
                <h2><a class="article-title" href="https://example.com/news/second-story">Second recovered article title</a></h2>
                <p>This is the second recovered summary and it has enough content to be valid.</p>
            </article>
            <article class="news-card">
                <time datetime="2026-03-23">Mar.23</time>
                <h2><a class="article-title" href="https://example.com/news/third-story">Third recovered article title</a></h2>
                <p>This is the third recovered summary and it has enough content to be valid.</p>
            </article>
        </main>
    </body>
</html>
HTML;

                $fetch = SourceFetch::query()->create([
                        'source_id' => $source->id,
                        'fetched_url_hash' => hash('sha256', 'https://example.com/news'),
                        'fetched_url' => 'https://example.com/news',
                        'http_status' => 200,
                        'content_hash' => hash('sha256', $payload),
                        'payload_bytes' => strlen($payload),
                        'fetched_at' => now(),
                        'response_headers' => [],
                ]);

                $payloadCacheKey = sprintf('ingestion:payload:%s:%s', $source->id, $fetch->content_hash);
                Cache::put($payloadCacheKey, $payload, now()->addMinutes(30));

                $job = new ParseArticlesJob((string) $source->id, ['fetch_id' => $fetch->id]);
                $job->handle(
                        app(FeedParserService::class),
                        app(SourceHealthTracker::class),
                        app(ParseAttemptTracker::class),
                        app(ParserSchemaRegistry::class),
                        app(HeuristicHtmlPatternDetector::class),
                );

                Queue::assertNotPushed(ResolveSchemaWithAiJob::class);
                Queue::assertPushed(NormalizeArticlesJob::class, function (NormalizeArticlesJob $queued) use ($source): bool {
                        return $queued->sourceId === (string) $source->id;
                });

                $source->refresh();
                $this->assertSame('parsed', $source->status);
                $this->assertNotSame($oldSchema->id, $source->activeParserSchema()->first()?->id);
                $this->assertSame('deterministic_html_schema', $source->activeParserSchema()->first()?->strategy_type);
                $this->assertSame(3, data_get($source->meta, 'parse.parsed_count'));
                $this->assertNotNull(data_get($source->meta, 'parse.schema_refresh.schema_id'));
        }
}
