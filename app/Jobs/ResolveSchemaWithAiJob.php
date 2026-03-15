<?php

namespace App\Jobs;

use App\Domain\Parsing\Contracts\AiSchemaResolver;
use App\Domain\Parsing\Contracts\SchemaValidator;
use App\Events\SourceSchemaRequested;
use App\Events\SourceSchemaResolved;
use App\Models\Source;
use App\Support\ParseAttemptTracker;
use App\Support\SourceHealthTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ResolveSchemaWithAiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

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
        return [30, 120, 300];
    }

    /**
     * Execute the job.
     */
    public function handle(
        AiSchemaResolver $aiSchemaResolver,
        SchemaValidator $schemaValidator,
        ParseAttemptTracker $attemptTracker,
        SourceHealthTracker $healthTracker,
    ): void {
        if ((bool) config('ingestion.ai_repair_enabled', true) === false) {
            return;
        }

        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $attempt = $attemptTracker->start(
            source: $source,
            stage: 'schema_repair_ai',
            context: $this->context,
            schema: $source->activeParserSchema()->first(),
        );

        $snapshot = $source->snapshots()->latest('captured_at')->first();
        $payload = trim((string) ($snapshot?->html_snapshot ?? ''));
        $finalUrl = (string) ($snapshot?->final_url ?: $source->canonical_url ?: $source->source_url);

        if ($payload === '') {
            $latestFetch = $source->fetches()->latest('fetched_at')->first();

            if ($latestFetch?->content_hash !== null) {
                $cacheKey = sprintf('ingestion:payload:%s:%s', $source->id, $latestFetch->content_hash);
                $cached = Cache::get($cacheKey);

                if (is_string($cached) && trim($cached) !== '') {
                    $payload = $cached;
                    $finalUrl = $latestFetch->fetched_url ?: $finalUrl;
                    $snapshot = $attemptTracker->captureSnapshot(
                        attempt: $attempt,
                        payload: $payload,
                        headers: is_array($latestFetch->response_headers) ? $latestFetch->response_headers : [],
                        finalUrl: $finalUrl,
                        snapshotKind: 'schema_repair_input',
                    );
                }
            }
        }

        if ($payload === '') {
            $attemptTracker->markSkipped($attempt, 'AI repair skipped: no payload available.');

            $source->update([
                'status' => 'repairing',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'ai_repair' => [
                        'status' => 'skipped',
                        'reason' => 'No snapshot/payload available for schema repair.',
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return;
        }

        SourceSchemaRequested::dispatch(
            sourceId: (string) $source->id,
            context: array_merge($this->context, [
                'pipeline_stage' => 'schema_repair_ai',
                'snapshot_id' => $snapshot?->id,
            ]),
        );

        try {
            $resolvedSchema = $aiSchemaResolver->resolve($payload, $finalUrl);
        } catch (Throwable $exception) {
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'ai_resolve_exception',
                errorMessage: $exception->getMessage(),
            );

            $healthTracker->markFailure(
                source: $source,
                stage: 'schema_repair_ai',
                errorType: 'ai_resolve_exception',
                message: $exception->getMessage(),
                context: $this->context,
            );

            throw $exception;
        }

        $validation = $schemaValidator->validate($resolvedSchema);

        if (! $validation->valid || ! (bool) ($resolvedSchema['valid'] ?? true)) {
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'ai_schema_invalid',
                errorMessage: implode(' ', $validation->errors),
                extra: [
                    'validation_errors' => $validation->errors,
                ],
            );

            $source->update([
                'status' => 'repairing',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'ai_repair' => [
                        'status' => 'invalid_schema',
                        'errors' => $validation->errors,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            SourceSchemaResolved::dispatch(
                sourceId: (string) $source->id,
                valid: false,
                schema: $resolvedSchema,
                context: array_merge($this->context, [
                    'validation_errors' => $validation->errors,
                ]),
            );

            return;
        }

        $nextVersion = ((int) $source->parserSchemas()->max('version')) + 1;
        $confidence = is_numeric($resolvedSchema['confidence'] ?? null)
            ? (float) $resolvedSchema['confidence']
            : null;

        $shadowSchemaPayload = array_merge($resolvedSchema, [
            'shadow_success_runs' => 0,
            'shadow_last_validated_at' => null,
        ]);

        $schema = $source->parserSchemas()->create([
            'version' => max(1, $nextVersion),
            'strategy_type' => 'ai_xpath_schema',
            'schema_payload' => $shadowSchemaPayload,
            'confidence_score' => $confidence !== null ? round($confidence * 100, 2) : null,
            'created_by' => 'ai_generated',
            'is_active' => false,
            'is_shadow' => true,
        ]);

        $attemptTracker->markSuccess($attempt, [
            'schema_id' => $schema->id,
            'snapshot_id' => $snapshot?->id,
        ]);

        $source->update([
            'status' => 'repairing',
            'meta' => array_merge((array) ($source->meta ?? []), [
                'ai_repair' => [
                    'status' => 'candidate_created',
                    'schema_id' => $schema->id,
                    'confidence' => $resolvedSchema['confidence'] ?? null,
                    'strategy' => $resolvedSchema['strategy'] ?? null,
                    'last_requested_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        SourceSchemaResolved::dispatch(
            sourceId: (string) $source->id,
            valid: true,
            schema: $resolvedSchema,
            context: array_merge($this->context, [
                'schema_id' => $schema->id,
                'pipeline_stage' => 'schema_repair_ai',
            ]),
        );

        ValidateSchemaJob::dispatch(
            sourceId: (string) $source->id,
            context: array_merge($this->context, [
                'trigger' => 'ai_schema_candidate',
                'parser_schema_id' => $schema->id,
                'pipeline_stage' => 'schema_validate',
                'snapshot_id' => $snapshot?->id,
            ]),
        )->onQueue('repair');
    }
}
