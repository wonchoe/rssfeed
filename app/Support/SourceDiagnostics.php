<?php

namespace App\Support;

use App\Models\Source;

class SourceDiagnostics
{
    /**
     * @param  array{id?:int,display_name?:string,failed_at?:string,exception_head?:string}|null  $failedJob
     * @return array{severity:string,code:string,reason:string,hint:?string}
     */
    public function diagnose(Source $source, ?array $failedJob = null, ?bool $hasQueueWorkers = null): array
    {
        $meta = (array) ($source->meta ?? []);
        $discovery = (array) ($meta['discovery'] ?? []);
        $fetch = (array) ($meta['fetch'] ?? []);
        $parse = (array) ($meta['parse'] ?? []);
        $detect = (array) ($meta['detect'] ?? []);

        $status = (string) $source->status;
        $usageState = (string) ($source->usage_state ?: ((int) ($source->active_subscriptions_count ?? 0) > 0 ? 'active' : 'inactive'));
        $healthState = (string) ($source->health_state ?: 'unknown');
        $healthScore = (int) ($source->health_score ?? 0);
        $statusCode = $fetch['status_code'] ?? $source->latestFetch?->http_status;
        $parsedCount = is_numeric($parse['parsed_count'] ?? null) ? (int) $parse['parsed_count'] : null;
        $newArticles = is_numeric($detect['new_articles'] ?? null) ? (int) $detect['new_articles'] : null;
        $duplicatesSkipped = is_numeric($detect['duplicates_skipped'] ?? null) ? (int) $detect['duplicates_skipped'] : null;
        $latestAttempt = $source->latestParseAttempt;

        if ($usageState !== 'active' && (int) ($source->active_subscriptions_count ?? 0) === 0) {
            $nextCheckAt = $source->next_check_at?->toIso8601String();

            return [
                'severity' => $usageState === 'cold' ? 'ok' : 'warn',
                'code' => $usageState === 'cold' ? 'source_cold' : 'source_inactive',
                'reason' => $usageState === 'cold'
                    ? 'Source is in cold state and is excluded from frequent polling.'
                    : 'Source has no active bindings/subscriptions; regular polling is paused.',
                'hint' => $nextCheckAt !== null
                    ? 'Next check at '.$nextCheckAt.'. Source refreshes immediately on demand.'
                    : 'Source refreshes on demand or when an active binding is created.',
            ];
        }

        if ($failedJob !== null) {
            return [
                'severity' => 'error',
                'code' => 'failed_job',
                'reason' => 'Last failed job: '.($failedJob['display_name'] ?? 'unknown').' at '.($failedJob['failed_at'] ?? 'n/a').'.',
                'hint' => $failedJob['exception_head'] ?? 'Open failed jobs/Horizon for stack trace.',
            ];
        }

        if ($latestAttempt !== null && $latestAttempt->status === 'failed') {
            $attemptReason = trim((string) ($latestAttempt->error_message ?? ''));
            $attemptType = trim((string) ($latestAttempt->error_type ?? 'pipeline_error'));
            $hint = $latestAttempt->snapshot_reference !== null
                ? 'Latest snapshot: '.$latestAttempt->snapshot_reference.'.'
                : 'Retry source and inspect parse attempts.';

            return [
                'severity' => 'error',
                'code' => 'parse_attempt_failed',
                'reason' => $attemptReason !== ''
                    ? $attemptReason
                    : 'Latest parse attempt failed ('.$attemptType.').',
                'hint' => $hint,
            ];
        }

        if ($latestAttempt !== null && $latestAttempt->status === 'skipped') {
            return [
                'severity' => 'warn',
                'code' => 'parse_attempt_skipped',
                'reason' => $latestAttempt->error_message ?: 'Latest parse attempt was skipped.',
                'hint' => 'Check source payload/cache availability and retry.',
            ];
        }

        if ($hasQueueWorkers === false && $source->last_parsed_at === null) {
            return [
                'severity' => 'error',
                'code' => 'no_queue_workers',
                'reason' => 'No active Horizon supervisors. Queue jobs are not being processed.',
                'hint' => 'Start queue workers (`php artisan horizon`) and retry source.',
            ];
        }

        if ($status === 'pending' && $source->last_fetched_at === null) {
            return [
                'severity' => 'warn',
                'code' => 'pending_never_fetched',
                'reason' => 'Source is pending and was never fetched.',
                'hint' => 'Retry source pipeline. Verify ingestion worker/scheduler is running.',
            ];
        }

        if ($status === 'discovery_failed') {
            $warnings = $discovery['warnings'] ?? [];
            $warning = is_array($warnings) && $warnings !== [] ? (string) $warnings[0] : null;

            return [
                'severity' => 'error',
                'code' => 'discovery_failed',
                'reason' => $warning ?? 'Discovery failed for this source.',
                'hint' => 'Check URL accessibility and feed autodiscovery tags.',
            ];
        }

        if ($status === 'fetch_failed') {
            return [
                'severity' => 'error',
                'code' => 'fetch_failed',
                'reason' => 'Fetch failed with HTTP '.($statusCode ?? 'n/a').'.',
                'hint' => 'Source may block bot traffic or URL may be invalid.',
            ];
        }

        if ($healthState === 'blocked') {
            return [
                'severity' => 'error',
                'code' => 'source_blocked',
                'reason' => 'Source appears blocked (health '.$healthState.', score '.$healthScore.').',
                'hint' => 'Try browser fallback strategy or reduce request frequency.',
            ];
        }

        if (str_contains($status, 'failed')) {
            $error = $parse['error'] ?? $fetch['error'] ?? $detect['error'] ?? null;

            return [
                'severity' => 'error',
                'code' => 'pipeline_failed',
                'reason' => is_string($error) && $error !== ''
                    ? $error
                    : 'Pipeline stage failed for this source.',
                'hint' => 'Retry the source and inspect failed jobs if issue repeats.',
            ];
        }

        if ($status === 'not_modified' && $source->articles_count === 0) {
            return [
                'severity' => 'warn',
                'code' => 'not_modified_without_articles',
                'reason' => 'Source returned 304, but no articles are stored yet.',
                'hint' => 'Force a fresh fetch to obtain first baseline payload.',
            ];
        }

        if ($source->last_fetched_at === null) {
            return [
                'severity' => 'warn',
                'code' => 'never_fetched',
                'reason' => 'Source has not been fetched yet.',
                'hint' => 'Retry source pipeline.',
            ];
        }

        if ($parsedCount === 0) {
            return [
                'severity' => 'warn',
                'code' => 'parsed_zero_items',
                'reason' => 'Fetch succeeded but parser returned zero feed items.',
                'hint' => 'Resolved URL may not be a valid RSS/Atom/JSON feed.',
            ];
        }

        if ($newArticles === 0 && $duplicatesSkipped !== null && $duplicatesSkipped > 0) {
            return [
                'severity' => 'ok',
                'code' => 'all_duplicates',
                'reason' => 'No new articles: all parsed items were duplicates.',
                'hint' => null,
            ];
        }

        if ($newArticles !== null && $newArticles > 0) {
            return [
                'severity' => 'ok',
                'code' => 'new_articles_detected',
                'reason' => 'Ingestion healthy. New articles detected: '.$newArticles.'.',
                'hint' => null,
            ];
        }

        return [
            'severity' => 'ok',
            'code' => 'healthy_idle',
            'reason' => 'Ingestion healthy. Waiting for source updates.',
            'hint' => null,
        ];
    }
}
