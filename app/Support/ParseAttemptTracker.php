<?php

namespace App\Support;

use App\Models\ParseAttempt;
use App\Models\ParserSchema;
use App\Models\Source;
use App\Models\SourceSnapshot;
use Throwable;

class ParseAttemptTracker
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function start(
        Source $source,
        string $stage,
        array $context = [],
        ?ParserSchema $schema = null,
    ): ParseAttempt {
        return ParseAttempt::query()->create([
            'source_id' => $source->id,
            'parser_schema_id' => $schema?->id,
            'stage' => $stage,
            'status' => 'running',
            'retry_count' => max(0, (int) ($context['retry_count'] ?? 0)),
            'used_browser' => (bool) ($context['used_browser'] ?? false),
            'used_ai' => (bool) ($context['used_ai'] ?? false),
            'context' => $context,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markSuccess(ParseAttempt $attempt, array $extra = []): ParseAttempt
    {
        $attempt->update([
            'status' => 'success',
            'http_status' => $this->nullableInt($extra['http_status'] ?? null),
            'response_time_ms' => $this->resolveResponseTimeMs($attempt),
            'finished_at' => now(),
            'context' => $this->mergeContext($attempt->context, $extra),
        ]);

        return $attempt->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markSkipped(ParseAttempt $attempt, ?string $reason = null, array $extra = []): ParseAttempt
    {
        $attempt->update([
            'status' => 'skipped',
            'error_type' => $reason !== null ? 'skipped' : null,
            'error_message' => $this->truncateErrorMessage($reason),
            'http_status' => $this->nullableInt($extra['http_status'] ?? null),
            'response_time_ms' => $this->resolveResponseTimeMs($attempt),
            'finished_at' => now(),
            'context' => $this->mergeContext($attempt->context, $extra),
        ]);

        return $attempt->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markFailure(
        ParseAttempt $attempt,
        string $errorType,
        ?string $errorMessage = null,
        array $extra = [],
    ): ParseAttempt {
        $attempt->update([
            'status' => 'failed',
            'error_type' => $errorType,
            'error_message' => $this->truncateErrorMessage($errorMessage),
            'http_status' => $this->nullableInt($extra['http_status'] ?? null),
            'response_time_ms' => $this->resolveResponseTimeMs($attempt),
            'finished_at' => now(),
            'context' => $this->mergeContext($attempt->context, $extra),
        ]);

        return $attempt->refresh();
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function captureSnapshot(
        ParseAttempt $attempt,
        string $payload,
        array $headers = [],
        ?string $finalUrl = null,
        string $snapshotKind = 'payload',
    ): ?SourceSnapshot {
        if ((bool) config('ingestion.capture_snapshots', true) === false) {
            return null;
        }

        $maxBytes = max(4096, (int) config('ingestion.snapshot_max_bytes', 250000));
        $snapshotBody = $this->sanitizeSnapshotPayload($payload, $maxBytes);
        $contentHash = $snapshotBody !== '' ? hash('sha256', $snapshotBody) : null;

        try {
            $snapshot = SourceSnapshot::query()->create([
                'source_id' => $attempt->source_id,
                'parse_attempt_id' => $attempt->id,
                'snapshot_kind' => $snapshotKind,
                'html_snapshot' => $snapshotBody !== '' ? $snapshotBody : null,
                'headers' => $headers,
                'final_url' => $finalUrl,
                'content_hash' => $contentHash,
                'captured_at' => now(),
            ]);
        } catch (Throwable) {
            return null;
        }

        $attempt->update([
            'snapshot_reference' => 'source_snapshot:'.$snapshot->id,
        ]);

        return $snapshot;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveResponseTimeMs(ParseAttempt $attempt): int
    {
        $startedAt = $attempt->started_at;

        if ($startedAt === null) {
            return 0;
        }

        $elapsedMs = (int) round($startedAt->diffInMilliseconds(now()));

        return max(0, $elapsedMs);
    }

    /**
     * @param  array<string, mixed>|null  $currentContext
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeContext(?array $currentContext, array $extra): array
    {
        return array_merge($currentContext ?? [], $extra);
    }

    private function truncateErrorMessage(?string $message, int $maxBytes = 4000): ?string
    {
        if ($message === null) {
            return null;
        }

        $normalized = $this->sanitizeUtf8($message);

        if ($normalized === '') {
            return null;
        }

        return $this->truncateUtf8($normalized, $maxBytes);
    }

    private function sanitizeSnapshotPayload(string $payload, int $maxBytes): string
    {
        $normalized = $this->sanitizeUtf8($payload);

        if ($normalized === '') {
            return '';
        }

        return $this->truncateUtf8($normalized, $maxBytes);
    }

    private function sanitizeUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if (is_string($sanitized) && $sanitized !== '') {
            return $sanitized;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1251, ISO-8859-1');

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return '';
    }

    private function truncateUtf8(string $value, int $maxBytes): string
    {
        if ($value === '' || $maxBytes <= 0) {
            return '';
        }

        if (function_exists('mb_strcut')) {
            return (string) mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        return substr($value, 0, $maxBytes);
    }
}
