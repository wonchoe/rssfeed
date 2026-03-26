<?php

namespace App\Console\Commands;

use App\Jobs\EnrichNewArticlesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReenrichArticlesCommand extends Command
{
    protected $signature = 'articles:reenrich
                            {--source= : Source ID (omit for all sources)}
                            {--batch=20 : Articles per job}
                            {--force : Re-enrich even if article already has summary and image}';

    protected $description = 'Re-enrich existing articles with og:image and og:description without re-delivering to subscriptions';

    public function handle(): int
    {
        $sourceId = $this->option('source');
        $batchSize = max(1, (int) $this->option('batch'));
        $force = (bool) $this->option('force');

        $query = DB::table('articles');

        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }

        if (! $force) {
            $query->where(function ($q): void {
                $q->whereNull('summary')
                    ->orWhere('summary', '')
                    ->orWhereNull('image_url')
                    ->orWhere('image_url', '');
            });
        }

        $ids = $query->pluck('id', 'source_id');

        if ($ids->isEmpty()) {
            $this->info('No articles need re-enrichment.');

            return self::SUCCESS;
        }

        // Group by source_id
        /** @var array<int|string, list<int>> $bySource */
        $bySource = [];
        foreach ($ids as $srcId => $artId) {
            $bySource[$srcId][] = (int) $artId;
        }

        // pluck returns (id => source_id) — re-query properly
        $rows = DB::table('articles')->when($sourceId !== null, fn ($q) => $q->where('source_id', $sourceId))
            ->when(! $force, fn ($q) => $q->where(function ($q): void {
                $q->whereNull('summary')->orWhere('summary', '')
                    ->orWhereNull('image_url')->orWhere('image_url', '');
            }))
            ->select('id', 'source_id')
            ->get();

        /** @var array<string, list<int>> $bySource */
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(string) $row->source_id][] = (int) $row->id;
        }

        $totalJobs = 0;
        $totalArticles = 0;

        foreach ($bySource as $srcId => $articleIds) {
            $chunks = array_chunk($articleIds, $batchSize);
            foreach ($chunks as $chunk) {
                EnrichNewArticlesJob::dispatch(
                    $srcId,
                    $chunk,
                    ['skip_delivery' => true, 'trigger' => 'reenrich_command'],
                );
                $totalJobs++;
            }
            $totalArticles += count($articleIds);
            $this->line("source {$srcId}: ".count($articleIds).' articles → '.count($chunks).' jobs');
        }

        $this->info("Queued {$totalArticles} articles across {$totalJobs} enrichment jobs (no delivery will be triggered).");

        return self::SUCCESS;
    }
}
