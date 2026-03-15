<?php

namespace Tests\Feature;

use App\Domain\Parsing\Contracts\FeedParserService;
use App\Jobs\ParseArticlesJob;
use App\Jobs\ResolveSchemaWithAiJob;
use App\Models\Source;
use App\Models\SourceFetch;
use App\Support\ParseAttemptTracker;
use App\Support\ParserSchemaRegistry;
use App\Support\SourceHealthTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
            'source_type' => 'html',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 45,
            'health_state' => 'degraded',
            'consecutive_failures' => 3,
            'polling_interval_minutes' => 30,
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
        );

        Queue::assertPushed(ResolveSchemaWithAiJob::class, function (ResolveSchemaWithAiJob $queued) use ($source): bool {
            return $queued->sourceId === (string) $source->id;
        });
    }
}
