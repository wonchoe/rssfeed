<?php

namespace App\Jobs;

use App\Data\Parsing\ParsedArticleData;
use App\Domain\Parsing\Contracts\FeedParserService;
use App\Events\ArticlesParsed;
use App\Models\ParseAttempt;
use App\Models\Source;
use App\Models\SourceFetch;
use App\Support\ParseAttemptTracker;
use App\Support\ParserSchemaRegistry;
use App\Support\PipelineStage;
use App\Support\SourceHealthTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ParseArticlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public ?int $parseAttemptId = null;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 45, 120];
    }

    /**
     * Execute the job.
     */
    public function handle(
        FeedParserService $feedParserService,
        SourceHealthTracker $healthTracker,
        ParseAttemptTracker $attemptTracker,
        ParserSchemaRegistry $schemaRegistry,
    ): void {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $attempt = $attemptTracker->start(
            source: $source,
            stage: PipelineStage::Parse->value,
            context: $this->context,
            schema: $schemaRegistry->activeSchemaFor($source),
        );
        $this->parseAttemptId = $attempt->id;

        $healthTracker->markAttempt($source, PipelineStage::Parse->value, $this->context);

        $source->update([
            'status' => 'parsing',
        ]);

        $fetchId = $this->context['fetch_id'] ?? null;
        $fetch = SourceFetch::query()
            ->where('source_id', $source->id)
            ->when(is_numeric($fetchId), fn ($query) => $query->where('id', (int) $fetchId))
            ->when(! is_numeric($fetchId), fn ($query) => $query->latest('fetched_at'))
            ->first();

        if ($fetch === null || $fetch->content_hash === null) {
            $attemptTracker->markSkipped($attempt, 'No fetched payload/content hash available for parsing.');

            $source->update([
                'status' => 'parse_skipped',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'parse' => [
                        'reason' => 'No fetched payload/content hash available for parsing.',
                        'stage' => PipelineStage::Parse->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return;
        }

        $payloadCacheKey = $this->context['payload_cache_key']
            ?? sprintf('ingestion:payload:%s:%s', $source->id, $fetch->content_hash);

        $payload = Cache::get($payloadCacheKey);

        if (! is_string($payload) || trim($payload) === '') {
            $attemptTracker->markSkipped($attempt, 'Payload cache is missing or empty.', [
                'cache_key' => $payloadCacheKey,
            ]);

            $source->update([
                'status' => 'parse_skipped',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'parse' => [
                        'reason' => 'Payload cache is missing or empty.',
                        'cache_key' => $payloadCacheKey,
                        'stage' => PipelineStage::Parse->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return;
        }

        $snapshot = $attemptTracker->captureSnapshot(
            attempt: $attempt,
            payload: $payload,
            headers: [],
            finalUrl: $fetch->fetched_url,
            snapshotKind: 'parse_input',
        );

        $parsedArticles = $feedParserService->parse(
            payload: $payload,
            sourceUrl: $fetch->fetched_url,
            sourceType: $source->source_type,
        );

        $parsedCacheKey = sprintf('ingestion:parsed:%s:%s', $source->id, $fetch->content_hash);
        $serialized = array_map(fn (ParsedArticleData $article): array => [
            'url' => $article->url,
            'title' => $article->title,
            'external_id' => $article->externalId,
            'summary' => $article->summary,
            'image_url' => $article->imageUrl,
            'published_at' => $article->publishedAt,
            'meta' => $article->meta,
        ], $parsedArticles);

        $parsedCount = count($serialized);

        Cache::put(
            $parsedCacheKey,
            $serialized,
            now()->addMinutes((int) config('ingestion.parsed_cache_ttl_minutes', 60)),
        );

        $source->update([
            'status' => $parsedCount === 0 ? 'active' : 'parsed',
            'last_parsed_at' => $parsedCount === 0 ? now() : $source->last_parsed_at,
            'meta' => array_merge((array) ($source->meta ?? []), [
                'parse' => [
                    'parsed_count' => $parsedCount,
                    'fetch_id' => $fetch->id,
                    'source_type' => $source->source_type,
                    'cache_key' => $parsedCacheKey,
                    'parse_attempt_id' => $attempt->id,
                    'snapshot_id' => $snapshot?->id,
                    'reason' => $parsedCount === 0
                        ? 'Parser returned zero items for the current payload.'
                        : null,
                    'stage' => PipelineStage::Parse->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        if ($parsedCount === 0) {
            $healthTracker->markFailure(
                source: $source,
                stage: PipelineStage::Parse->value,
                errorType: 'parse_empty',
                message: 'Parser returned zero items for payload.',
                context: $this->context,
            );
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'parse_empty',
                errorMessage: 'Parser returned zero items for payload.',
                extra: [
                    'fetch_id' => $fetch->id,
                    'parsed_count' => 0,
                    'snapshot_id' => $snapshot?->id,
                ],
            );
            $source->refresh();
            $this->dispatchRepairWorkflowIfNeeded($source, $attempt->id);

            return;
        }

        $healthTracker->markSuccess($source, PipelineStage::Parse->value, [
            'parsed_count' => $parsedCount,
        ]);
        $attemptTracker->markSuccess($attempt, [
            'parsed_count' => $parsedCount,
            'fetch_id' => $fetch->id,
            'snapshot_id' => $snapshot?->id,
        ]);

        ArticlesParsed::dispatch(
            sourceId: (string) $source->id,
            articleCount: $parsedCount,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Parse->value,
                'parsed_cache_key' => $parsedCacheKey,
                'parsed_count' => $parsedCount,
                'parse_attempt_id' => $attempt->id,
            ]),
        );

        NormalizeArticlesJob::dispatch(
            sourceId: (string) $source->id,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Normalize->value,
                'parsed_cache_key' => $parsedCacheKey,
            ]),
        )->onQueue('ingestion');
    }

    public function failed(Throwable $exception): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $attemptTracker = app(ParseAttemptTracker::class);
        $attempt = $this->parseAttemptId !== null
            ? ParseAttempt::query()->find($this->parseAttemptId)
            : $attemptTracker->start($source, PipelineStage::Parse->value, $this->context);

        if ($attempt !== null) {
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'parse_exception',
                errorMessage: $exception->getMessage(),
                extra: $this->context,
            );
        }

        app(SourceHealthTracker::class)->markFailure(
            source: $source,
            stage: PipelineStage::Parse->value,
            errorType: 'parse_exception',
            message: $exception->getMessage(),
            context: $this->context,
        );

        $source->update([
            'status' => 'parse_failed',
            'meta' => array_merge((array) ($source->meta ?? []), [
                'parse' => [
                    'error' => $exception->getMessage(),
                    'stage' => PipelineStage::Parse->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $source->refresh();
        $this->dispatchRepairWorkflowIfNeeded($source, $attempt?->id);
    }

    private function dispatchRepairWorkflowIfNeeded(Source $source, ?int $attemptId = null): void
    {
        if ((bool) config('ingestion.ai_repair_enabled', true) === false) {
            return;
        }

        $context = array_merge($this->context, [
            'trigger' => 'parse_degraded',
            'parse_attempt_id' => $attemptId,
            'pipeline_stage' => 'schema_repair_trigger',
        ]);

        $activeShadowSchema = $source->parserSchemas()
            ->where('is_shadow', true)
            ->latest('id')
            ->first();

        if ($activeShadowSchema !== null) {
            ValidateSchemaJob::dispatch(
                sourceId: (string) $source->id,
                context: array_merge($context, [
                    'parser_schema_id' => $activeShadowSchema->id,
                    'trigger' => 'shadow_revalidate',
                ]),
            )->onQueue('repair');

            return;
        }

        if (! in_array($source->source_type, ['unknown', 'html'], true)) {
            return;
        }

        $failureThreshold = max(1, (int) config('ingestion.ai_repair_failure_threshold', 3));

        if ((int) $source->consecutive_failures < $failureThreshold) {
            return;
        }

        $meta = (array) ($source->meta ?? []);
        $aiRepairMeta = (array) ($meta['ai_repair'] ?? []);
        $lastRequestedAtRaw = $aiRepairMeta['last_requested_at'] ?? null;
        $cooldownMinutes = max(1, (int) config('ingestion.ai_repair_cooldown_minutes', 60));

        if (is_string($lastRequestedAtRaw) && trim($lastRequestedAtRaw) !== '') {
            try {
                $lastRequestedAt = Carbon::parse($lastRequestedAtRaw);

                if ($lastRequestedAt->gt(now()->subMinutes($cooldownMinutes))) {
                    return;
                }
            } catch (Throwable) {
                // Invalid timestamps should not block a new repair request.
            }
        }

        $source->update([
            'status' => 'repairing',
            'meta' => array_merge($meta, [
                'ai_repair' => array_merge($aiRepairMeta, [
                    'status' => 'queued',
                    'last_requested_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ]),
            ]),
        ]);

        ResolveSchemaWithAiJob::dispatch(
            sourceId: (string) $source->id,
            context: $context,
        )->onQueue('repair');
    }
}
