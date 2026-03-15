<?php

namespace App\Support;

use App\Models\Source;

class SourceHealthTracker
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function markAttempt(Source $source, string $stage, array $context = []): void
    {
        $meta = (array) ($source->meta ?? []);
        $healthMeta = (array) ($meta['health'] ?? []);

        $healthMeta['last_stage'] = $stage;
        $healthMeta['last_attempt_at'] = now()->toIso8601String();

        if ($context !== []) {
            $healthMeta['last_context'] = $context;
        }

        $source->update([
            'last_attempt_at' => now(),
            'meta' => array_merge($meta, [
                'health' => $healthMeta,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markSuccess(Source $source, string $stage, array $context = []): void
    {
        $meta = (array) ($source->meta ?? []);
        $healthMeta = (array) ($meta['health'] ?? []);
        $nextScore = min(100, max(40, (int) $source->health_score) + 8);

        $healthMeta['last_stage'] = $stage;
        $healthMeta['last_success_at'] = now()->toIso8601String();
        $healthMeta['last_error_type'] = null;
        $healthMeta['last_error_message'] = null;

        if ($context !== []) {
            $healthMeta['last_success_context'] = $context;
        }

        $source->update([
            'last_success_at' => now(),
            'consecutive_failures' => 0,
            'health_score' => $nextScore,
            'health_state' => $this->stateByScore($nextScore),
            'meta' => array_merge($meta, [
                'health' => $healthMeta,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markFailure(
        Source $source,
        string $stage,
        string $errorType,
        ?string $message = null,
        ?int $httpStatus = null,
        array $context = [],
    ): void {
        $meta = (array) ($source->meta ?? []);
        $healthMeta = (array) ($meta['health'] ?? []);
        $failures = max(0, (int) $source->consecutive_failures) + 1;
        $scoreDrop = in_array($errorType, ['timeout', 'network', 'http_5xx'], true) ? 10 : 15;
        $nextScore = max(0, (int) $source->health_score - $scoreDrop);

        $healthMeta['last_stage'] = $stage;
        $healthMeta['last_error_type'] = $errorType;
        $healthMeta['last_error_message'] = $message;
        $healthMeta['last_failure_at'] = now()->toIso8601String();
        $healthMeta['last_http_status'] = $httpStatus;

        if ($context !== []) {
            $healthMeta['last_failure_context'] = $context;
        }

        $source->update([
            'consecutive_failures' => $failures,
            'health_score' => $nextScore,
            'health_state' => $this->stateByFailure($nextScore, $failures, $httpStatus),
            'meta' => array_merge($meta, [
                'health' => $healthMeta,
            ]),
        ]);
    }

    private function stateByScore(int $score): string
    {
        return match (true) {
            $score >= 80 => 'healthy',
            $score >= 55 => 'degraded',
            $score >= 35 => 'repairing',
            default => 'broken',
        };
    }

    private function stateByFailure(int $score, int $failures, ?int $httpStatus): string
    {
        if ($httpStatus !== null && in_array($httpStatus, [403, 429], true)) {
            return 'blocked';
        }

        if ($failures >= 5 || $score < 25) {
            return 'broken';
        }

        if ($failures >= 2) {
            return 'repairing';
        }

        return $this->stateByScore($score);
    }
}
