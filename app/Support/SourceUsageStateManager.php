<?php

namespace App\Support;

use App\Models\Source;
use Illuminate\Support\Carbon;

class SourceUsageStateManager
{
    public function refresh(Source $source, bool $forceImmediateCheck = false): Source
    {
        $activeBindings = $source->subscriptions()
            ->where('is_active', true)
            ->count();

        $usageState = $this->resolveUsageState($source, $activeBindings);

        $updates = [
            'usage_state' => $usageState,
        ];

        if ($forceImmediateCheck) {
            $updates['next_check_at'] = now();
        } elseif ($source->next_check_at === null || $source->usage_state !== $usageState) {
            $updates['next_check_at'] = $this->nextCheckAtFor($source, $usageState);
        }

        $source->fill($updates);

        if ($source->isDirty()) {
            $source->save();
        }

        return $source;
    }

    public function nextCheckAtFor(Source $source, ?string $usageState = null): Carbon
    {
        $usageState ??= $source->usage_state ?: 'inactive';
        $interval = $this->intervalMinutesFor($source, $usageState);
        $jitter = random_int(0, max(0, (int) config('ingestion.schedule_jitter_seconds', 120)));

        return now()->addMinutes($interval)->addSeconds($jitter);
    }

    public function intervalMinutesFor(Source $source, ?string $usageState = null): int
    {
        $usageState ??= $source->usage_state ?: 'inactive';

        return match ($usageState) {
            'active' => max(5, (int) $source->polling_interval_minutes),
            'cold' => max(60, (int) config('ingestion.cold_polling_interval_minutes', 10080)),
            default => max(60, (int) config('ingestion.inactive_polling_interval_minutes', 1440)),
        };
    }

    private function resolveUsageState(Source $source, int $activeBindings): string
    {
        if ($activeBindings > 0) {
            return 'active';
        }

        $coldAfterDays = max(1, (int) config('ingestion.cold_source_after_days', 30));
        $reference = $source->last_success_at ?? $source->updated_at ?? $source->created_at;

        if ($reference !== null && $reference->lte(now()->subDays($coldAfterDays))) {
            return 'cold';
        }

        return 'inactive';
    }
}
