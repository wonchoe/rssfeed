@extends('layouts.workspace')

@section('title', 'Feed Profile · rss.cursor.style')
@section('page_title', 'Feed Profile')
@section('page_subtitle', $source->canonical_url ?: $source->source_url)

@section('top_actions')
    <a href="{{ route('feeds.generator') }}" class="btn">+ New Feed</a>
    <a href="{{ route('dashboard') }}" class="btn-secondary">Back to Dashboard</a>
@endsection

@push('styles')
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 14px;
            align-items: start;
        }

        .card { padding: 18px; }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .mini {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px;
            background: rgba(9, 18, 34, 0.7);
        }

        .mini p { margin: 0; }

        .mini .value {
            margin-top: 5px;
            font-size: 22px;
            font-weight: 800;
            font-family: "Manrope", sans-serif;
        }

        .section-title {
            margin: 0 0 10px;
            font-size: 20px;
            font-family: "Manrope", sans-serif;
        }

        .feed-link {
            color: #9dcaff;
            font-size: 12px;
            word-break: break-all;
        }

        .list {
            display: grid;
            gap: 8px;
        }

        .item {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(11, 20, 37, 0.68);
            padding: 10px;
            display: grid;
            gap: 6px;
        }

        .item h4 {
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }

        .item p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .key-grid {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 8px;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .key-grid .label { color: var(--muted); }

        .live-strip {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 12px;
            display: grid;
            gap: 6px;
            background: rgba(10, 20, 38, 0.76);
        }

        .live-strip .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 12px;
            color: var(--muted);
        }

        .live-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #f59e0b;
            box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.45);
            animation: livePulse 1.6s infinite;
            display: inline-block;
        }

        .live-dot.ok { background: #22c55e; }
        .live-dot.err { background: #ef4444; }

        @keyframes livePulse {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.45); }
            70% { box-shadow: 0 0 0 7px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }

        @media (max-width: 1100px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@section('content')
    <section class="stats">
        <article class="mini">
            <p class="muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.55px;">Articles</p>
            <p class="value" id="stat-articles">{{ number_format($stats['articles']) }}</p>
        </article>
        <article class="mini">
            <p class="muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.55px;">Active Subs</p>
            <p class="value" id="stat-active-subscriptions">{{ number_format($stats['activeSubscriptions']) }}</p>
        </article>
        <article class="mini">
            <p class="muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.55px;">Fetches</p>
            <p class="value" id="stat-fetches">{{ number_format($stats['fetches']) }}</p>
        </article>
    </section>

    <section class="profile-grid">
        <article class="panel card">
            <h3 class="section-title">Source Details</h3>

            <div class="live-strip">
                <div class="row">
                    <span>Live Monitor</span>
                    <span><span id="live-dot" class="live-dot"></span> <span id="live-connection">connecting...</span></span>
                </div>
                <div class="row">
                    <span>Latest Fetch</span>
                    <span id="live-last-fetch">{{ $recentFetches->first()?->fetched_at?->toDateTimeString() ?? 'n/a' }}</span>
                </div>
                <div class="row">
                    <span>Latest Article</span>
                    <span id="live-last-article">{{ $recentArticles->first()?->title ?? 'n/a' }}</span>
                </div>
            </div>

            <div class="key-grid">
                <div class="label">Requested URL</div>
                <div><a class="feed-link" href="{{ $source->source_url }}" target="_blank" rel="noopener noreferrer">{{ $source->source_url }}</a></div>

                <div class="label">Resolved URL</div>
                <div>
                    @if ($source->canonical_url)
                        <a class="feed-link" href="{{ $source->canonical_url }}" target="_blank" rel="noopener noreferrer">{{ $source->canonical_url }}</a>
                    @else
                        <span class="muted">n/a</span>
                    @endif
                </div>

                <div class="label">Type</div>
                <div id="source-type">{{ strtoupper($source->source_type) }}</div>

                <div class="label">Status</div>
                <div id="source-status">{{ $source->status }}</div>

                <div class="label">Last Fetched</div>
                <div id="source-last-fetched">{{ $source->last_fetched_at?->toDateTimeString() ?? 'never' }}</div>

                <div class="label">Last Parsed</div>
                <div id="source-last-parsed">{{ $source->last_parsed_at?->toDateTimeString() ?? 'never' }}</div>
            </div>

            <h4 style="margin:16px 0 8px;">Recent Articles</h4>
            @if ($recentArticles->isEmpty())
                <p class="muted">No parsed articles yet.</p>
            @else
                <div class="list">
                    @foreach ($recentArticles as $article)
                        <article class="item">
                            <h4>{{ $article->title }}</h4>
                            <a class="feed-link" href="{{ $article->canonical_url }}" target="_blank" rel="noopener noreferrer">{{ $article->canonical_url }}</a>
                            @if ($article->summary)
                                <p>{{ \Illuminate\Support\Str::limit($article->summary, 210) }}</p>
                            @endif
                            <p>published: {{ $article->published_at?->toDateTimeString() ?? 'n/a' }} | discovered: {{ $article->discovered_at?->toDateTimeString() ?? 'n/a' }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </article>

        <article class="panel card">
            <h3 class="section-title">Your Subscriptions</h3>

            @if ($userSubscriptions->isEmpty())
                <p class="muted">No subscriptions found for this source.</p>
            @else
                <div class="list" style="margin-bottom:14px;">
                    @foreach ($userSubscriptions as $subscription)
                        <article class="item">
                            <div style="display:flex; justify-content:space-between; gap:10px; align-items:center;">
                                <strong>{{ strtoupper($subscription->channel) }}</strong>
                                @if ($subscription->is_active)
                                    <span class="badge badge-ok">Active</span>
                                @else
                                    <span class="badge badge-muted">Paused</span>
                                @endif
                            </div>
                            <p>
                                @if (in_array($subscription->channel, ['slack', 'discord', 'teams'], true))
                                    {{ data_get($subscription->config, 'webhook_label') ?: 'Saved '.strtoupper($subscription->channel).' webhook' }}
                                @else
                                    {{ $subscription->target }}
                                @endif
                            </p>
                        </article>
                    @endforeach
                </div>
            @endif

            <h3 class="section-title" style="margin-top:6px;">Recent Fetches</h3>
            @if ($recentFetches->isEmpty())
                <p class="muted">No fetch history yet.</p>
            @else
                <div class="list">
                    @foreach ($recentFetches as $fetch)
                        <article class="item">
                            <p><strong>HTTP {{ $fetch->http_status ?? 'n/a' }}</strong> at {{ $fetch->fetched_at?->toDateTimeString() ?? 'n/a' }}</p>
                            <p>bytes: {{ $fetch->payload_bytes ?? 0 }} | hash: {{ $fetch->content_hash ? \Illuminate\Support\Str::limit($fetch->content_hash, 22) : 'n/a' }}</p>
                            <a class="feed-link" href="{{ $fetch->fetched_url }}" target="_blank" rel="noopener noreferrer">{{ $fetch->fetched_url }}</a>
                        </article>
                    @endforeach
                </div>
            @endif
        </article>
    </section>
@endsection

@push('scripts')
    <script>
        const streamUrl = "{{ route('feeds.show.stream', $source) }}";
        const liveConnection = document.getElementById('live-connection');
        const liveDot = document.getElementById('live-dot');
        const liveLastFetch = document.getElementById('live-last-fetch');
        const liveLastArticle = document.getElementById('live-last-article');
        const statArticles = document.getElementById('stat-articles');
        const statActiveSubscriptions = document.getElementById('stat-active-subscriptions');
        const statFetches = document.getElementById('stat-fetches');
        const sourceType = document.getElementById('source-type');
        const sourceStatus = document.getElementById('source-status');
        const sourceLastFetched = document.getElementById('source-last-fetched');
        const sourceLastParsed = document.getElementById('source-last-parsed');

        function formatDate(value) {
            if (!value) {
                return 'n/a';
            }

            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return value;
            }

            return date.toLocaleString();
        }

        function updateFromPayload(payload) {
            if (!payload || payload.ok !== true) {
                return;
            }

            sourceType.textContent = String(payload.source?.source_type || '').toUpperCase();
            sourceStatus.textContent = payload.source?.status || 'unknown';
            sourceLastFetched.textContent = payload.source?.last_fetched_at ? formatDate(payload.source.last_fetched_at) : 'never';
            sourceLastParsed.textContent = payload.source?.last_parsed_at ? formatDate(payload.source.last_parsed_at) : 'never';

            statArticles.textContent = Number(payload.stats?.articles || 0).toLocaleString();
            statActiveSubscriptions.textContent = Number(payload.stats?.active_subscriptions || 0).toLocaleString();
            statFetches.textContent = Number(payload.stats?.fetches || 0).toLocaleString();

            liveLastFetch.textContent = payload.latest_fetch?.fetched_at ? formatDate(payload.latest_fetch.fetched_at) : 'n/a';
            liveLastArticle.textContent = payload.latest_article?.title || 'n/a';
        }

        const sourceStream = new EventSource(streamUrl);

        sourceStream.addEventListener('status', (event) => {
            try {
                const payload = JSON.parse(event.data);
                updateFromPayload(payload);
                liveConnection.textContent = 'live';
                liveDot.classList.add('ok');
                liveDot.classList.remove('err');
            } catch (error) {
                // no-op
            }
        });

        sourceStream.onerror = () => {
            liveConnection.textContent = 'reconnecting...';
            liveDot.classList.remove('ok');
            liveDot.classList.add('err');
        };
    </script>
@endpush
