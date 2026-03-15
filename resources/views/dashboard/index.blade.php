@extends('layouts.workspace')

@section('title', 'My Feeds · rss.cursor.style')
@section('page_title', 'My Feeds')
@section('page_subtitle', 'Generate previews, map feeds to Telegram destinations, and track source activity.')

@section('top_actions')
    <a href="{{ route('integrations.index') }}" class="btn-secondary">Manage Integrations</a>
@endsection

@push('styles')
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .stat-card {
            padding: 16px;
        }

        .stat-label {
            margin: 0 0 8px;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: .55px;
            color: var(--muted);
        }

        .stat-value {
            margin: 0;
            font-family: "Manrope", sans-serif;
            font-size: 30px;
            font-weight: 800;
        }

        .card {
            padding: 18px;
        }

        .section-title {
            margin: 0 0 6px;
            font-size: 20px;
            font-family: "Manrope", sans-serif;
        }

        .subscription-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty {
            padding: 26px;
            border: 1px dashed var(--line);
            border-radius: 14px;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 940px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <section class="stats-grid">
        <article class="panel stat-card">
            <p class="stat-label">Total Subscriptions</p>
            <p class="stat-value">{{ number_format($stats['subscriptions']) }}</p>
        </article>
        <article class="panel stat-card">
            <p class="stat-label">Active</p>
            <p class="stat-value">{{ number_format($stats['activeSubscriptions']) }}</p>
        </article>
        <article class="panel stat-card">
            <p class="stat-label">Tracked Sources</p>
            <p class="stat-value">{{ number_format($stats['sources']) }}</p>
        </article>
    </section>

    <section style="margin-bottom:14px;">
        @include('partials.feed-builder-panel', [
            'feedBuilderRedirectTo' => route('dashboard', absolute: false),
            'feedBuilderCreatedFrom' => 'dashboard_builder',
            'telegramGroupChats' => $telegramGroupChats,
            'telegramChannelChats' => $telegramChannelChats,
        ])
    </section>

    <section class="panel card">
        <h3 class="section-title">Subscriptions</h3>

        @if ($subscriptions->isEmpty())
            <div class="empty">
                <p style="margin:0;">No feeds yet. Generate your first preview above and map it to a Telegram group or channel.</p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Source</th>
                        <th>Channel</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($subscriptions as $subscription)
                        <tr>
                            <td>
                                <div style="font-weight:600;">
                                    <a href="{{ route('feeds.show', $subscription->source) }}" style="color:#9bc8ff;">
                                        {{ $subscription->source->source_url }}
                                    </a>
                                </div>
                                <div class="muted" style="font-size:12px; margin-top:4px;">type: {{ $subscription->source->source_type }}</div>
                            </td>
                            <td>{{ strtoupper($subscription->channel) }}</td>
                            <td>
                                @if ($subscription->channel === 'telegram' && $subscription->telegramChat !== null)
                                    <div style="font-weight:600;">{{ $subscription->telegramChat->displayName() }}</div>
                                    <div class="muted" style="font-size:12px; margin-top:4px;">{{ $subscription->telegramChat->kindLabel() }} · {{ $subscription->target }}</div>
                                @else
                                    {{ $subscription->target }}
                                @endif
                            </td>
                            <td>
                                @if ($subscription->is_active)
                                    <span class="badge badge-ok">Active</span>
                                @else
                                    <span class="badge badge-muted">Paused</span>
                                @endif
                            </td>
                            <td>
                                <div class="subscription-actions">
                                    <form method="POST" action="{{ route('subscriptions.update', $subscription) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $subscription->is_active ? 0 : 1 }}">
                                        <button class="btn-secondary" type="submit">{{ $subscription->is_active ? 'Pause' : 'Activate' }}</button>
                                    </form>

                                    <form method="POST" action="{{ route('subscriptions.destroy', $subscription) }}" onsubmit="return confirm('Delete this subscription?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top:12px;">
                {{ $subscriptions->links() }}
            </div>
        @endif
    </section>
@endsection
