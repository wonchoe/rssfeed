<?php

namespace App\Jobs;

use App\Models\ParserSchema;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RevalidateActiveSchemasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(): void
    {
        if ((bool) config('ingestion.schema_revalidation_enabled', true) === false) {
            return;
        }

        $intervalHours = max(1, (int) config('ingestion.schema_revalidation_interval_hours', 12));
        $limit = max(1, (int) config('ingestion.schema_revalidation_max_dispatch_per_cycle', 50));
        $cutoff = now()->subHours($intervalHours);

        ParserSchema::query()
            ->with('source')
            ->where('is_active', true)
            ->where('is_shadow', false)
            ->whereIn('strategy_type', ['deterministic_html_schema', 'ai_xpath_schema'])
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_validated_at')
                    ->orWhere('last_validated_at', '<=', $cutoff);
            })
            ->whereHas('source', function ($query): void {
                $query->where('source_type', 'html')
                    ->where('usage_state', 'active');
            })
            ->orderBy('last_validated_at')
            ->limit($limit)
            ->get()
            ->each(function (ParserSchema $schema): void {
                ValidateSchemaJob::dispatch(
                    sourceId: (string) $schema->source_id,
                    context: [
                        'trigger' => 'scheduled_active_schema_revalidate',
                        'pipeline_stage' => 'schema_validate',
                        'parser_schema_id' => $schema->id,
                    ],
                )->onQueue('repair');
            });
    }
}