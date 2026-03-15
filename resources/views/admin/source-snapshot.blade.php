@extends('layouts.workspace')

@section('title', 'Source Snapshot | rss.cursor.style')
@section('page_title', 'Source Snapshot')
@section('page_subtitle', 'Latest captured payload and parse attempt diagnostics for a source.')

@section('top_actions')
    <a href="{{ route('admin.debug.generations') }}" class="btn-secondary">Back to Admin Debug</a>
    <a href="{{ $source->canonical_url ?? $source->source_url }}" target="_blank" rel="noopener noreferrer" class="btn">Visit Source</a>
@endsection

@push('styles')
    <style>
        .snapshot-grid {
            display: grid;
            gap: 14px;
        }

        .snapshot-card {
            padding: 14px;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .meta-cell {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px;
            background: rgba(12, 22, 41, 0.72);
        }

        .mono-box {
            margin-top: 10px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(7, 14, 30, 0.9);
            padding: 12px;
            max-height: 70vh;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            color: #c4d6ff;
        }

        @media (max-width: 900px) {
            .meta-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <section class="snapshot-grid">
        <article class="panel snapshot-card">
            <h3 style="margin:0 0 8px;">Source</h3>
            <div class="muted">URL: {{ $source->source_url }}</div>
            <div class="muted">Canonical: {{ $source->canonical_url ?? 'n/a' }}</div>
            <div class="muted">Status: {{ $source->status }} | Usage: {{ $source->usage_state }} | Health: {{ $source->health_state }} ({{ $source->health_score }})</div>
        </article>

        <article class="panel snapshot-card">
            <h3 style="margin:0 0 10px;">Latest Parse Attempt</h3>
            @if ($latestAttempt)
                <div class="meta-grid">
                    <div class="meta-cell">Attempt ID: {{ $latestAttempt->id }}</div>
                    <div class="meta-cell">Stage: {{ $latestAttempt->stage }}</div>
                    <div class="meta-cell">Status: {{ $latestAttempt->status }}</div>
                    <div class="meta-cell">HTTP: {{ $latestAttempt->http_status ?? 'n/a' }}</div>
                    <div class="meta-cell">Error Type: {{ $latestAttempt->error_type ?? 'n/a' }}</div>
                    <div class="meta-cell">Response Time: {{ $latestAttempt->response_time_ms ?? 'n/a' }} ms</div>
                    <div class="meta-cell">Started: {{ $latestAttempt->started_at?->toIso8601String() ?? 'n/a' }}</div>
                    <div class="meta-cell">Finished: {{ $latestAttempt->finished_at?->toIso8601String() ?? 'n/a' }}</div>
                </div>
                @if ($latestAttempt->error_message)
                    <p class="muted" style="margin-top:10px;">{{ $latestAttempt->error_message }}</p>
                @endif
            @else
                <p class="muted">No parse attempts recorded yet.</p>
            @endif
        </article>

        <article class="panel snapshot-card">
            <h3 style="margin:0 0 10px;">Latest Snapshot</h3>
            @if ($latestSnapshot)
                <div class="meta-grid">
                    <div class="meta-cell">Snapshot ID: {{ $latestSnapshot->id }}</div>
                    <div class="meta-cell">Kind: {{ $latestSnapshot->snapshot_kind }}</div>
                    <div class="meta-cell">Captured At: {{ $latestSnapshot->captured_at?->toIso8601String() ?? 'n/a' }}</div>
                    <div class="meta-cell">Content Hash: {{ $latestSnapshot->content_hash ?? 'n/a' }}</div>
                    <div class="meta-cell">Final URL: {{ $latestSnapshot->final_url ?? 'n/a' }}</div>
                </div>
                <div class="mono-box">{{ $latestSnapshot->html_snapshot ?? '[empty snapshot]' }}</div>
            @else
                <p class="muted">No snapshot captured yet.</p>
            @endif
        </article>
    </section>
@endsection

