<?php

namespace App\Jobs;

use App\Models\Source;
use App\Support\PipelineStage;
use App\Support\SourceUsageStateManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollSourcesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(SourceUsageStateManager $usageStateManager): void
    {
        $pollInactiveSources = (bool) config('ingestion.poll_inactive_sources', false);
        $maxPerDomain = max(1, (int) config('ingestion.scheduler_max_per_domain_per_cycle', 2));
        $maxDispatchPerCycle = max(1, (int) config('ingestion.scheduler_max_dispatch_per_cycle', 180));
        $dispatchedByDomain = [];
        $dispatchedTotal = 0;

        Source::query()
            ->withCount([
                'subscriptions as active_subscriptions_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->where(function ($query) use ($pollInactiveSources): void {
                $query->whereHas('subscriptions', fn ($inner) => $inner->where('is_active', true));

                if ($pollInactiveSources) {
                    $query->orWhereIn('usage_state', ['inactive', 'cold']);
                }
            })
            ->orderBy('id')
            ->chunkById(100, function ($sources) use (
                $usageStateManager,
                $pollInactiveSources,
                $maxPerDomain,
                $maxDispatchPerCycle,
                &$dispatchedByDomain,
                &$dispatchedTotal,
            ): void {
                foreach ($sources as $source) {
                    if ($dispatchedTotal >= $maxDispatchPerCycle) {
                        return;
                    }

                    $usageState = $this->resolveUsageState(
                        source: $source,
                        activeSubscriptions: (int) ($source->active_subscriptions_count ?? 0),
                    );

                    if ($source->usage_state !== $usageState) {
                        $source->update([
                            'usage_state' => $usageState,
                        ]);
                    }

                    if ($usageState !== 'active' && ! $pollInactiveSources) {
                        continue;
                    }

                    if (! $this->isDue($source, $usageState)) {
                        continue;
                    }

                    $domain = $this->domainForSource($source);
                    $domainCount = $dispatchedByDomain[$domain] ?? 0;

                    if ($domainCount >= $maxPerDomain) {
                        $source->update([
                            'next_check_at' => now()->addSeconds(random_int(25, 120)),
                            'meta' => array_merge((array) ($source->meta ?? []), [
                                'scheduler' => [
                                    'reason' => 'domain_cycle_limit',
                                    'domain' => $domain,
                                    'max_per_domain' => $maxPerDomain,
                                    'updated_at' => now()->toIso8601String(),
                                ],
                            ]),
                        ]);

                        continue;
                    }

                    $source->update([
                        'usage_state' => $usageState,
                        'next_check_at' => $usageStateManager->nextCheckAtFor($source, $usageState),
                    ]);

                    $context = [
                        'trigger' => 'scheduler_poll',
                        'pipeline_stage' => PipelineStage::Fetch->value,
                        'usage_state' => $usageState,
                        'domain' => $domain,
                    ];

                    if (in_array($source->source_type, ['unknown', 'html'], true) || $source->status === 'discovery_failed') {
                        dispatch((new DiscoverSourceTypeJob(
                            sourceId: (string) $source->id,
                            context: $context,
                        ))->onQueue('ingestion'));

                        $dispatchedByDomain[$domain] = $domainCount + 1;
                        $dispatchedTotal++;

                        continue;
                    }

                    dispatch((new FetchSourceJob(
                        sourceId: (string) $source->id,
                        context: $context,
                    ))->onQueue('ingestion'));

                    $dispatchedByDomain[$domain] = $domainCount + 1;
                    $dispatchedTotal++;
                }
            });
    }

    private function isDue(Source $source, string $usageState): bool
    {
        if ($source->next_check_at !== null) {
            return $source->next_check_at->lte(now());
        }

        if ($source->last_fetched_at === null) {
            return true;
        }

        $intervalMinutes = match ($usageState) {
            'active' => max(5, (int) $source->polling_interval_minutes),
            'cold' => max(60, (int) config('ingestion.cold_polling_interval_minutes', 10080)),
            default => max(60, (int) config('ingestion.inactive_polling_interval_minutes', 1440)),
        };

        return now()->diffInMinutes($source->last_fetched_at) >= $intervalMinutes;
    }

    private function resolveUsageState(Source $source, int $activeSubscriptions): string
    {
        if ($activeSubscriptions > 0) {
            return 'active';
        }

        if ($source->usage_state === 'cold') {
            return 'cold';
        }

        return 'inactive';
    }

    private function domainForSource(Source $source): string
    {
        $host = trim((string) ($source->host ?: parse_url($source->canonical_url ?: $source->source_url, PHP_URL_HOST)));

        return $host !== '' ? strtolower($host) : 'unknown';
    }
}
