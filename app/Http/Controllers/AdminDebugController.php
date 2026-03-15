<?php

namespace App\Http\Controllers;

use App\Jobs\DiscoverSourceTypeJob;
use App\Jobs\FetchSourceJob;
use App\Jobs\GenerateFeedPreviewJob;
use App\Models\FeedGeneration;
use App\Models\Source;
use App\Support\FeedGenerationWatchdog;
use App\Support\HorizonRuntime;
use App\Support\SourceDiagnostics;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class AdminDebugController extends Controller
{
    public function index(
        FeedGenerationWatchdog $watchdog,
        SourceDiagnostics $sourceDiagnostics,
        HorizonRuntime $horizonRuntime,
    ): View {
        $watchdog->markTimedOutBatch(300);

        $timeoutSeconds = $watchdog->timeoutSeconds();
        $cutoff = now()->subSeconds($timeoutSeconds);
        $pendingStatuses = $watchdog->pendingStatuses();

        $stalePending = FeedGeneration::query()
            ->with('user:id,name,email')
            ->whereIn('status', $pendingStatuses)
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(function (Builder $inner) use ($cutoff): void {
                        $inner
                            ->whereNull('started_at')
                            ->where('created_at', '<=', $cutoff);
                    })
                    ->orWhere('started_at', '<=', $cutoff);
            })
            ->latest('created_at')
            ->limit(40)
            ->get();

        $recentFailed = FeedGeneration::query()
            ->with('user:id,name,email')
            ->where('status', 'failed')
            ->latest('updated_at')
            ->limit(60)
            ->get();

        $recent = FeedGeneration::query()
            ->with('user:id,name,email')
            ->latest('created_at')
            ->limit(120)
            ->get();

        $queueMetrics = [
            'ingestion' => null,
            'delivery' => null,
            'default' => null,
            'error' => null,
        ];

        try {
            foreach (['ingestion', 'delivery', 'default'] as $queue) {
                $queueMetrics[$queue] = Redis::llen('queues:'.$queue);
            }
        } catch (Throwable $exception) {
            $queueMetrics['error'] = $exception->getMessage();
        }

        $failedJobsCount = null;

        try {
            $failedJobsCount = DB::table('failed_jobs')->count();
        } catch (Throwable $exception) {
            Log::warning('Failed jobs table is not available.', [
                'error' => $exception->getMessage(),
            ]);
        }

        $supervisorsCount = $horizonRuntime->supervisorsCount();
        $hasQueueWorkers = $horizonRuntime->hasActiveSupervisors();

        $sources = Source::query()
            ->with(['latestFetch', 'latestParseAttempt', 'latestSnapshot'])
            ->withCount([
                'articles',
                'subscriptions as active_subscriptions_count' => fn (Builder $query) => $query->where('is_active', true),
            ])
            ->orderByDesc('updated_at')
            ->limit(120)
            ->get();

        $latestFailedBySource = $this->latestFailedJobsBySource();

        $sourceRows = $sources->map(function (Source $source) use (
            $sourceDiagnostics,
            $latestFailedBySource,
            $hasQueueWorkers,
        ): array {
            $failedJob = $latestFailedBySource[$source->id] ?? null;
            $diagnostic = $sourceDiagnostics->diagnose($source, $failedJob, $hasQueueWorkers);

            return [
                'source' => $source,
                'diagnostic' => $diagnostic,
            ];
        });

        return view('admin.debug-generations', [
            'timeoutSeconds' => $timeoutSeconds,
            'stalePending' => $stalePending,
            'recentFailed' => $recentFailed,
            'recent' => $recent,
            'failedJobsCount' => $failedJobsCount,
            'queueMetrics' => $queueMetrics,
            'supervisorsCount' => $supervisorsCount,
            'hasQueueWorkers' => $hasQueueWorkers,
            'sourceRows' => $sourceRows,
            'stats' => [
                'pending' => FeedGeneration::query()->whereIn('status', $pendingStatuses)->count(),
                'stalePending' => $stalePending->count(),
                'failed' => FeedGeneration::query()->where('status', 'failed')->count(),
                'failedTimedOut' => FeedGeneration::query()
                    ->where('status', 'failed')
                    ->where('meta->timed_out', true)
                    ->count(),
                'ready' => FeedGeneration::query()->where('status', 'ready')->count(),
            ],
        ]);
    }

    public function retry(Request $request, FeedGeneration $feedGeneration): RedirectResponse
    {
        $meta = (array) ($feedGeneration->meta ?? []);
        $runToken = (string) Str::uuid();

        $feedGeneration->update([
            'status' => 'queued',
            'message' => 'Queued for generation (admin retry).',
            'resolved_url' => null,
            'source_type' => null,
            'preview_items' => [],
            'started_at' => null,
            'finished_at' => null,
            'meta' => array_merge($meta, [
                'timed_out' => false,
                'escalated_to_admin_debug' => false,
                'admin_retry_count' => (int) ($meta['admin_retry_count'] ?? 0) + 1,
                'last_admin_retry_at' => now()->toIso8601String(),
                'last_admin_retry_by' => (int) $request->user()->id,
                'run_token' => $runToken,
            ]),
        ]);

        GenerateFeedPreviewJob::dispatch((string) $feedGeneration->id, $runToken)->onQueue('ingestion');

        return redirect()
            ->route('admin.debug.generations')
            ->with('status', 'Generation #'.$feedGeneration->id.' was re-queued.');
    }

    public function retrySource(Request $request, Source $source): RedirectResponse
    {
        $meta = (array) ($source->meta ?? []);

        $source->update([
            'meta' => array_merge($meta, [
                'admin' => [
                    'last_source_retry_at' => now()->toIso8601String(),
                    'last_source_retry_by' => (int) $request->user()->id,
                ],
            ]),
        ]);

        $context = [
            'trigger' => 'admin_source_retry',
            'admin_user_id' => (int) $request->user()->id,
        ];

        $needsDiscovery = in_array($source->source_type, ['unknown', 'html'], true)
            || in_array($source->status, ['pending', 'discovery_failed', 'parse_failed', 'normalize_failed', 'detect_failed'], true)
            || $source->canonical_url === null;

        if ($needsDiscovery) {
            DiscoverSourceTypeJob::dispatch((string) $source->id, $context)->onQueue('ingestion');

            return redirect()
                ->route('admin.debug.generations')
                ->with('status', 'Source #'.$source->id.' queued for discovery retry.');
        }

        FetchSourceJob::dispatch((string) $source->id, $context)->onQueue('ingestion');

        return redirect()
            ->route('admin.debug.generations')
            ->with('status', 'Source #'.$source->id.' queued for fetch retry.');
    }

    public function sourceSnapshot(Source $source): View
    {
        $latestSnapshot = $source->snapshots()
            ->latest('captured_at')
            ->first();

        $latestAttempt = $source->parseAttempts()
            ->latest('started_at')
            ->first();

        return view('admin.source-snapshot', [
            'source' => $source,
            'latestSnapshot' => $latestSnapshot,
            'latestAttempt' => $latestAttempt,
        ]);
    }

    /**
     * @return array<int, array{id:int,display_name:string,failed_at:string,exception_head:string}>
     */
    private function latestFailedJobsBySource(): array
    {
        try {
            /** @var Collection<int, object> $rows */
            $rows = DB::table('failed_jobs')
                ->select(['id', 'payload', 'exception', 'failed_at'])
                ->orderByDesc('id')
                ->limit(250)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $mapped = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->payload ?? ''), true);

            if (! is_array($payload)) {
                continue;
            }

            $commandString = $payload['data']['command'] ?? null;

            if (! is_string($commandString) || $commandString === '') {
                continue;
            }

            if (! preg_match('/sourceId";s:\d+:"([^"]+)"/', $commandString, $matches)) {
                continue;
            }

            $sourceId = (int) $matches[1];

            if ($sourceId < 1 || isset($mapped[$sourceId])) {
                continue;
            }

            $exception = (string) ($row->exception ?? '');
            $exceptionHead = $exception !== '' ? explode("\n", $exception)[0] : 'Unknown failure';

            $mapped[$sourceId] = [
                'id' => (int) ($row->id ?? 0),
                'display_name' => (string) ($payload['displayName'] ?? 'unknown'),
                'failed_at' => (string) ($row->failed_at ?? ''),
                'exception_head' => $exceptionHead,
            ];
        }

        return $mapped;
    }
}
