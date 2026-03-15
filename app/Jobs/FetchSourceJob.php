<?php

namespace App\Jobs;

use App\Events\SourceFetched;
use App\Events\SourceFetchRequested;
use App\Models\ParseAttempt;
use App\Models\Source;
use App\Models\SourceFetch;
use App\Support\ParseAttemptTracker;
use App\Support\PipelineStage;
use App\Support\SourceHealthTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class FetchSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

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
        return [15, 60, 180, 300];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        if (app()->environment('testing')) {
            return [];
        }

        return [
            (new WithoutOverlapping('fetch-source:'.$this->sourceId))
                ->releaseAfter(10)
                ->expireAfter(180),
            (new WithoutOverlapping('fetch-domain:'.$this->fetchDomainKey()))
                ->releaseAfter((int) config('ingestion.fetch_domain_release_seconds', 15))
                ->expireAfter(180),
            new RateLimitedWithRedis('fetch-domain'),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(
        SourceHealthTracker $healthTracker,
        ParseAttemptTracker $attemptTracker,
    ): void {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $attempt = $attemptTracker->start(
            source: $source,
            stage: PipelineStage::Fetch->value,
            context: $this->context,
            schema: $source->activeParserSchema()->first(),
        );
        $this->parseAttemptId = $attempt->id;

        $healthTracker->markAttempt($source, PipelineStage::Fetch->value, $this->context);

        $fetchUrl = $source->canonical_url ?: $source->source_url;
        $lastFetch = SourceFetch::query()
            ->where('source_id', $source->id)
            ->latest('fetched_at')
            ->first();

        $headers = [];

        if ($lastFetch?->etag !== null) {
            $headers['If-None-Match'] = $lastFetch->etag;
        }

        if ($lastFetch?->last_modified !== null) {
            $headers['If-Modified-Since'] = $lastFetch->last_modified;
        }

        SourceFetchRequested::dispatch(
            sourceId: (string) $source->id,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Fetch->value,
                'fetch_url' => $fetchUrl,
            ]),
        );

        $response = Http::retry(
            (int) config('ingestion.fetch.retry_times', 2),
            (int) config('ingestion.fetch.retry_sleep_ms', 300),
        )
            ->timeout((int) config('ingestion.fetch.timeout_seconds', 20))
            ->withUserAgent((string) config('ingestion.fetch.user_agent'))
            ->withHeaders($headers)
            ->accept('*/*')
            ->get($fetchUrl);

        $statusCode = $response->status();
        $payload = $statusCode === 200 ? $response->body() : '';
        $payloadBytes = strlen($payload);
        $contentHash = $payload !== '' ? hash('sha256', $payload) : null;

        $fetchRecord = SourceFetch::query()->create([
            'source_id' => $source->id,
            'fetched_url_hash' => hash('sha256', Str::lower($fetchUrl)),
            'fetched_url' => $fetchUrl,
            'http_status' => $statusCode,
            'etag' => $response->header('ETag'),
            'last_modified' => $response->header('Last-Modified'),
            'content_hash' => $contentHash,
            'payload_bytes' => $payloadBytes > 0 ? $payloadBytes : null,
            'fetched_at' => now(),
            'request_context' => array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Fetch->value,
            ]),
            'response_headers' => $response->headers(),
        ]);

        $payloadCacheKey = null;
        $snapshot = null;

        if ($statusCode === 200 && $contentHash !== null) {
            $payloadCacheKey = sprintf('ingestion:payload:%s:%s', $source->id, $contentHash);

            Cache::put(
                $payloadCacheKey,
                $payload,
                now()->addMinutes((int) config('ingestion.payload_cache_ttl_minutes', 120)),
            );
        }

        if ($payload !== '') {
            $snapshot = $attemptTracker->captureSnapshot(
                attempt: $attempt,
                payload: $payload,
                headers: $response->headers(),
                finalUrl: $fetchUrl,
                snapshotKind: 'fetch_payload',
            );
        }

        $sourceStatus = match (true) {
            $statusCode === 304 => 'not_modified',
            $statusCode >= 200 && $statusCode < 300 => 'fetched',
            default => 'fetch_failed',
        };

        $source->update([
            'status' => $sourceStatus,
            'last_attempt_at' => now(),
            'last_fetched_at' => now(),
            'latest_content_hash' => $contentHash ?? $source->latest_content_hash,
            'meta' => array_merge((array) ($source->meta ?? []), [
                'fetch' => [
                    'status_code' => $statusCode,
                    'payload_bytes' => $payloadBytes,
                    'cache_key' => $payloadCacheKey,
                    'retry_after_seconds' => $this->retryAfterSeconds($response->header('Retry-After')),
                    'parse_attempt_id' => $attempt->id,
                    'snapshot_id' => $snapshot?->id,
                    'updated_at' => now()->toIso8601String(),
                    'stage' => PipelineStage::Fetch->value,
                ],
            ]),
        ]);

        $retryAfterSeconds = $this->retryAfterSeconds($response->header('Retry-After'));

        if ($statusCode === 429 && $retryAfterSeconds !== null) {
            $source->update([
                'next_check_at' => now()->addSeconds($retryAfterSeconds),
            ]);
        }

        if ($statusCode >= 200 && $statusCode < 400) {
            $healthTracker->markSuccess($source, PipelineStage::Fetch->value, [
                'status_code' => $statusCode,
                'fetch_url' => $fetchUrl,
            ]);
            $attemptTracker->markSuccess($attempt, [
                'http_status' => $statusCode,
                'fetch_id' => $fetchRecord->id,
                'payload_bytes' => $payloadBytes,
                'snapshot_id' => $snapshot?->id,
            ]);
        } else {
            $healthTracker->markFailure(
                source: $source,
                stage: PipelineStage::Fetch->value,
                errorType: $this->errorTypeFromStatus($statusCode),
                message: 'Fetch returned HTTP '.$statusCode.'.',
                httpStatus: $statusCode,
                context: [
                    'fetch_url' => $fetchUrl,
                    ...$this->context,
                ],
            );
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: $this->errorTypeFromStatus($statusCode),
                errorMessage: 'Fetch returned HTTP '.$statusCode.'.',
                extra: [
                    'http_status' => $statusCode,
                    'fetch_id' => $fetchRecord->id,
                    'snapshot_id' => $snapshot?->id,
                ],
            );
        }

        SourceFetched::dispatch(
            sourceId: (string) $source->id,
            statusCode: $statusCode,
            contentHash: $contentHash,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Fetch->value,
                'fetch_id' => $fetchRecord->id,
                'fetch_url' => $fetchUrl,
                'payload_cache_key' => $payloadCacheKey,
                'parse_attempt_id' => $attempt->id,
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
            : $attemptTracker->start($source, PipelineStage::Fetch->value, $this->context);

        if ($attempt !== null) {
            $attemptTracker->markFailure(
                attempt: $attempt,
                errorType: 'fetch_exception',
                errorMessage: $exception->getMessage(),
                extra: $this->context,
            );
        }

        app(SourceHealthTracker::class)->markFailure(
            source: $source,
            stage: PipelineStage::Fetch->value,
            errorType: 'fetch_exception',
            message: $exception->getMessage(),
            context: $this->context,
        );

        $source->update([
            'status' => 'fetch_failed',
            'meta' => array_merge((array) ($source->meta ?? []), [
                'fetch' => [
                    'error' => $exception->getMessage(),
                    'stage' => PipelineStage::Fetch->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }

    private function errorTypeFromStatus(int $statusCode): string
    {
        return match (true) {
            $statusCode === 429 => 'http_429',
            $statusCode === 403 => 'http_403',
            $statusCode >= 500 => 'http_5xx',
            $statusCode >= 400 => 'http_4xx',
            default => 'http_'.$statusCode,
        };
    }

    public function fetchDomainKey(): string
    {
        $domainFromContext = trim((string) ($this->context['domain'] ?? ''));

        if ($domainFromContext !== '') {
            return Str::lower($domainFromContext);
        }

        $source = Source::query()->find($this->sourceId);

        if ($source !== null) {
            $host = trim((string) ($source->host ?: parse_url($source->canonical_url ?: $source->source_url, PHP_URL_HOST)));

            if ($host !== '') {
                return Str::lower($host);
            }
        }

        return 'unknown';
    }

    private function retryAfterSeconds(mixed $headerValue): ?int
    {
        if (is_array($headerValue)) {
            $headerValue = $headerValue[0] ?? null;
        }

        if ($headerValue === null) {
            return null;
        }

        $value = trim((string) $headerValue);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return max(1, (int) $value);
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return max(1, now()->diffInSeconds($parsed, false));
    }
}
