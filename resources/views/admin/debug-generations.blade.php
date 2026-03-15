@extends('layouts.workspace')

@section('title', 'Admin Debug | rss.cursor.style')
@section('page_title', 'Admin Debug Console')
@section('page_subtitle', 'Track stuck feed generations, queue pressure, and retry failed previews.')

@section('top_actions')
    <a href="{{ route('dashboard') }}" class="btn-secondary">Back to Dashboard</a>
    <a href="/horizon" target="_blank" rel="noopener noreferrer" class="btn">Open Horizon</a>
@endsection

@push('styles')
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-card {
            padding: 14px;
        }

        .stat-card .label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            margin-top: 8px;
            font-size: 26px;
            font-weight: 800;
            font-family: "Manrope", sans-serif;
        }

        .section {
            margin-top: 16px;
        }

        .section .heading {
            margin: 0 0 10px;
            font-size: 17px;
            font-family: "Manrope", sans-serif;
        }

        .queue-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .queue-cell {
            padding: 12px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: rgba(12, 20, 36, 0.64);
        }

        .queue-cell .name {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .queue-cell .count {
            font-size: 22px;
            font-weight: 800;
            font-family: "Manrope", sans-serif;
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            color: #c3d5ff;
        }

        .warn {
            color: #ffd38d;
        }

        .row-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-mini {
            border: 1px solid var(--line);
            background: rgba(23, 39, 68, 0.74);
            color: var(--text);
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .diag-ok {
            color: #9aefb8;
        }

        .diag-warn {
            color: #ffd38d;
        }

        .diag-error {
            color: #ffb6b6;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .queue-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .stats-grid,
            .queue-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <div class="stats-grid">
        <section class="panel stat-card">
            <div class="label">Pending</div>
            <div class="value">{{ $stats['pending'] }}</div>
        </section>
        <section class="panel stat-card">
            <div class="label">Stale Pending</div>
            <div class="value warn">{{ $stats['stalePending'] }}</div>
        </section>
        <section class="panel stat-card">
            <div class="label">Failed</div>
            <div class="value">{{ $stats['failed'] }}</div>
        </section>
        <section class="panel stat-card">
            <div class="label">Timed Out</div>
            <div class="value warn">{{ $stats['failedTimedOut'] }}</div>
        </section>
        <section class="panel stat-card">
            <div class="label">Ready</div>
            <div class="value">{{ $stats['ready'] }}</div>
        </section>
    </div>

    <section class="panel" style="padding:14px;">
        <h3 class="heading">Queue Snapshot</h3>
        <div class="queue-grid">
            <div class="queue-cell">
                <div class="name">Ingestion Queue</div>
                <div class="count">{{ $queueMetrics['ingestion'] ?? 'n/a' }}</div>
            </div>
            <div class="queue-cell">
                <div class="name">Delivery Queue</div>
                <div class="count">{{ $queueMetrics['delivery'] ?? 'n/a' }}</div>
            </div>
            <div class="queue-cell">
                <div class="name">Default Queue</div>
                <div class="count">{{ $queueMetrics['default'] ?? 'n/a' }}</div>
            </div>
            <div class="queue-cell">
                <div class="name">Failed Jobs Table</div>
                <div class="count">{{ $failedJobsCount ?? 'n/a' }}</div>
            </div>
            <div class="queue-cell">
                <div class="name">Horizon Supervisors</div>
                <div class="count">{{ $supervisorsCount ?? 'n/a' }}</div>
            </div>
        </div>
        @if ($queueMetrics['error'])
            <p class="muted" style="margin:10px 0 0;">
                Redis metrics unavailable: <span class="mono">{{ $queueMetrics['error'] }}</span>
            </p>
        @endif
        @if ($hasQueueWorkers === false)
            <p class="warn" style="margin:10px 0 0;">
                No active queue supervisors. Ingestion jobs will stay in queue until Horizon workers are started.
            </p>
        @endif
    </section>

    <section class="section panel" style="padding:14px;">
        <h3 class="heading">Source Diagnostics</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Last Fetch</th>
                    <th>Articles</th>
                    <th>Subscribers</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($sourceRows as $row)
                    @php($source = $row['source'])
                    @php($diag = $row['diagnostic'])
                    @php($diagClass = $diag['severity'] === 'error' ? 'diag-error' : ($diag['severity'] === 'warn' ? 'diag-warn' : 'diag-ok'))
                    <tr>
                        <td>
                            <div class="mono">{{ $source->source_url }}</div>
                            <div class="muted" style="font-size:12px;">
                                Canonical: <span class="mono">{{ $source->canonical_url ?? 'n/a' }}</span>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-muted">{{ $source->status }}</span>
                            <div class="muted" style="font-size:12px; margin-top:6px;">
                                Type: {{ $source->source_type }}
                            </div>
                            <div class="muted" style="font-size:12px; margin-top:6px;">
                                Usage: {{ $source->usage_state ?? 'n/a' }}
                            </div>
                            <div class="muted" style="font-size:12px; margin-top:6px;">
                                Health: {{ $source->health_state ?? 'n/a' }} ({{ $source->health_score ?? 'n/a' }})
                            </div>
                        </td>
                        <td>
                            <div class="{{ $diagClass }}">{{ $diag['reason'] }}</div>
                            @if ($diag['hint'])
                                <div class="muted" style="font-size:12px; margin-top:6px;">
                                    {{ $diag['hint'] }}
                                </div>
                            @endif
                            @if ($source->latestParseAttempt)
                                <div class="muted" style="font-size:12px; margin-top:6px;">
                                    Attempt #{{ $source->latestParseAttempt->id }} · {{ $source->latestParseAttempt->stage }} · {{ $source->latestParseAttempt->status }}
                                    @if ($source->latestParseAttempt->error_type)
                                        · {{ $source->latestParseAttempt->error_type }}
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            <div>{{ $source->last_fetched_at?->diffForHumans() ?? 'never' }}</div>
                            <div class="muted" style="font-size:12px; margin-top:6px;">
                                HTTP: {{ $source->latestFetch?->http_status ?? 'n/a' }}
                            </div>
                        </td>
                        <td>{{ $source->articles_count }}</td>
                        <td>{{ $source->active_subscriptions_count }}</td>
                        <td class="row-actions">
                            <form method="POST" action="{{ route('admin.debug.sources.retry', $source) }}">
                                @csrf
                                <button type="submit" class="btn-mini">Retry Source</button>
                            </form>
                            <a class="btn-mini" href="{{ route('admin.debug.sources.snapshot', $source) }}">Snapshot</a>
                            <a class="btn-mini" href="{{ $source->canonical_url ?? $source->source_url }}" target="_blank" rel="noopener noreferrer">Visit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">No sources found for diagnostics.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="section panel" style="padding:14px;">
        <h3 class="heading">Stale Pending Generations (>{{ $timeoutSeconds }}s)</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Requested URL</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($stalePending as $generation)
                    <tr>
                        <td>#{{ $generation->id }}</td>
                        <td>
                            <div>{{ $generation->user?->name ?? 'n/a' }}</div>
                            <div class="mono">{{ $generation->user?->email ?? 'n/a' }}</div>
                        </td>
                        <td><span class="badge badge-muted">{{ $generation->status }}</span></td>
                        <td class="mono">{{ $generation->requested_url }}</td>
                        <td>{{ $generation->created_at?->diffForHumans() ?? 'n/a' }}</td>
                        <td>{{ $generation->updated_at?->diffForHumans() ?? 'n/a' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.debug.generations.retry', $generation) }}">
                                @csrf
                                <button type="submit" class="btn-mini">Retry</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">No stale pending generations right now.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="section panel" style="padding:14px;">
        <h3 class="heading">Recent Failed Generations</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Message</th>
                    <th>Requested URL</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($recentFailed as $generation)
                    <tr>
                        <td>#{{ $generation->id }}</td>
                        <td>
                            <div>{{ $generation->user?->name ?? 'n/a' }}</div>
                            <div class="mono">{{ $generation->user?->email ?? 'n/a' }}</div>
                        </td>
                        <td>
                            @if (($generation->meta['timed_out'] ?? false) === true)
                                <span class="badge badge-muted">failed: timed_out</span>
                            @else
                                <span class="badge badge-muted">{{ $generation->status }}</span>
                            @endif
                        </td>
                        <td>{{ $generation->message }}</td>
                        <td class="mono">{{ $generation->requested_url }}</td>
                        <td>{{ $generation->updated_at?->diffForHumans() ?? 'n/a' }}</td>
                        <td class="row-actions">
                            <form method="POST" action="{{ route('admin.debug.generations.retry', $generation) }}">
                                @csrf
                                <button type="submit" class="btn-mini">Retry</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">No failed generations in recent history.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="section panel" style="padding:14px;">
        <h3 class="heading">Latest Generations</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Requested URL</th>
                    <th>Resolved URL</th>
                    <th>Created</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($recent as $generation)
                    <tr>
                        <td>#{{ $generation->id }}</td>
                        <td>
                            <div>{{ $generation->user?->name ?? 'n/a' }}</div>
                            <div class="mono">{{ $generation->user?->email ?? 'n/a' }}</div>
                        </td>
                        <td>
                            @if ($generation->status === 'ready')
                                <span class="badge badge-ok">ready</span>
                            @else
                                <span class="badge badge-muted">{{ $generation->status }}</span>
                            @endif
                        </td>
                        <td class="mono">{{ $generation->requested_url }}</td>
                        <td class="mono">{{ $generation->resolved_url ?? 'n/a' }}</td>
                        <td>{{ $generation->created_at?->diffForHumans() ?? 'n/a' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="muted">No generations found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
