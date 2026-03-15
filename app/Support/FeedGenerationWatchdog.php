<?php

namespace App\Support;

use App\Models\FeedGeneration;
use Illuminate\Database\Eloquent\Builder;

class FeedGenerationWatchdog
{
    /**
     * @return array<int, string>
     */
    public function pendingStatuses(): array
    {
        return ['queued', 'discovering', 'fetching', 'parsing'];
    }

    public function timeoutSeconds(): int
    {
        return max(30, (int) config('ingestion.feed_generation_timeout_seconds', 180));
    }

    public function markTimedOut(FeedGeneration $generation): bool
    {
        if (! in_array($generation->status, $this->pendingStatuses(), true)) {
            return false;
        }

        $timeoutSeconds = $this->timeoutSeconds();
        $referenceAt = $generation->started_at ?? $generation->created_at;

        if ($referenceAt === null || $referenceAt->gt(now()->subSeconds($timeoutSeconds))) {
            return false;
        }

        $meta = (array) ($generation->meta ?? []);

        $generation->update([
            'status' => 'failed',
            'message' => 'Generation timed out after '.$timeoutSeconds.' seconds. Escalated to admin debug.',
            'finished_at' => now(),
            'meta' => array_merge($meta, [
                'timed_out' => true,
                'timed_out_after_seconds' => $timeoutSeconds,
                'timed_out_at' => now()->toIso8601String(),
                'escalated_to_admin_debug' => true,
            ]),
        ]);

        return true;
    }

    public function markTimedOutBatch(int $limit = 200): int
    {
        $timeoutSeconds = $this->timeoutSeconds();
        $cutoff = now()->subSeconds($timeoutSeconds);

        $candidates = FeedGeneration::query()
            ->whereIn('status', $this->pendingStatuses())
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(function (Builder $inner) use ($cutoff): void {
                        $inner
                            ->whereNull('started_at')
                            ->where('created_at', '<=', $cutoff);
                    })
                    ->orWhere('started_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $marked = 0;

        foreach ($candidates as $generation) {
            if ($this->markTimedOut($generation)) {
                $marked++;
            }
        }

        return $marked;
    }
}
