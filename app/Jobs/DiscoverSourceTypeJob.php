<?php

namespace App\Jobs;

use App\Domain\Source\Contracts\SourceDiscoveryService;
use App\Events\SourceDiscovered;
use App\Models\ParseAttempt;
use App\Models\Source;
use App\Support\ParseAttemptTracker;
use App\Support\ParserSchemaRegistry;
use App\Support\PipelineStage;
use App\Support\SourceCatalog;
use App\Support\SourceHealthTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class DiscoverSourceTypeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

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
        return [10, 30, 90];
    }

    /**
     * Execute the job.
     */
    public function handle(
        SourceDiscoveryService $sourceDiscoveryService,
        SourceCatalog $sourceCatalog,
        SourceHealthTracker $healthTracker,
        ParserSchemaRegistry $schemaRegistry,
        ParseAttemptTracker $attemptTracker,
    ): void {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $attempt = $attemptTracker->start(
            source: $source,
            stage: PipelineStage::SourceDiscovery->value,
            context: $this->context,
            schema: $schemaRegistry->activeSchemaFor($source),
        );
        $this->parseAttemptId = $attempt->id;

        $healthTracker->markAttempt($source, PipelineStage::SourceDiscovery->value, $this->context);

        $result = $sourceDiscoveryService->discover($source->source_url);
        $primary = $result->primaryCandidate;

        if ($primary === null) {
            $healthTracker->markFailure(
                source: $source,
                stage: PipelineStage::SourceDiscovery->value,
                errorType: 'discovery_not_found',
                message: 'Discovery did not return a supported feed candidate.',
                context: $this->context,
            );
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'discovery_not_found',
                errorMessage: 'Discovery did not return a supported feed candidate.',
                extra: $this->context,
            );

            $source->update([
                'status' => 'discovery_failed',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'discovery' => [
                        'warnings' => $result->warnings,
                        'stage' => PipelineStage::SourceDiscovery->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return;
        }

        $discoveredUrl = $primary->canonicalUrl ?? $primary->url;
        $sourceCatalog->attachAlias($source, $discoveredUrl);

        $source->update([
            'source_type' => $primary->type,
            'status' => 'discovered',
            'canonical_url' => $discoveredUrl,
            'canonical_url_hash' => hash('sha256', Str::lower($discoveredUrl)),
            'meta' => array_merge((array) ($source->meta ?? []), [
                'discovery' => [
                    'warnings' => $result->warnings,
                    'strategy' => $primary->meta['strategy'] ?? null,
                    'confidence' => $primary->confidence,
                    'requested_url' => $result->requestedUrl,
                    'resolved_url' => $discoveredUrl,
                    'stage' => PipelineStage::SourceDiscovery->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
        $sourceCatalog->updateSourceHostMeta($source, $discoveredUrl);
        $schemaRegistry->ensureActiveFeedSchema(
            source: $source,
            sourceType: $primary->type,
            confidence: $primary->confidence,
            schemaPayload: [
                'source_type' => $primary->type,
                'strategy' => $primary->meta['strategy'] ?? null,
                'requested_url' => $result->requestedUrl,
                'resolved_url' => $discoveredUrl,
                'required_fields' => ['title', 'url'],
                'optional_fields' => ['summary', 'published_at'],
            ],
        );
        $healthTracker->markSuccess($source, PipelineStage::SourceDiscovery->value, [
            'confidence' => $primary->confidence,
            'source_type' => $primary->type,
        ]);
        $attemptTracker->markSuccess($attempt, [
            'resolved_url' => $discoveredUrl,
            'source_type' => $primary->type,
            'confidence' => $primary->confidence,
        ]);

        SourceDiscovered::dispatch(
            sourceId: (string) $source->id,
            sourceUrl: $discoveredUrl,
            sourceType: $primary->type,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::SourceDiscovery->value,
                'discovery_confidence' => $primary->confidence,
                'discovery_warnings' => $result->warnings,
            ]),
        );
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
            : $attemptTracker->start($source, PipelineStage::SourceDiscovery->value, $this->context);

        if ($attempt !== null) {
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'discovery_exception',
                errorMessage: $exception->getMessage(),
                extra: $this->context,
            );
        }

        app(SourceHealthTracker::class)->markFailure(
            source: $source,
            stage: PipelineStage::SourceDiscovery->value,
            errorType: 'discovery_exception',
            message: $exception->getMessage(),
            context: $this->context,
        );

        $source->update([
            'status' => 'discovery_failed',
            'meta' => array_merge((array) ($source->meta ?? []), [
                'discovery' => [
                    'error' => $exception->getMessage(),
                    'stage' => PipelineStage::SourceDiscovery->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }
}
