@extends('layouts.workspace')

@section('title', 'Integrations · rss.cursor.style')
@section('page_title', 'Integrations')
@section('page_subtitle', 'Link provider accounts and manage delivery destinations before attaching feeds to them.')

@section('top_actions')
    <a href="{{ route('feeds.generator') }}" class="btn-secondary">Back to Generator</a>
@endsection

@push('styles')
    <style>
        .integration-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .integration-stat {
            padding: 16px;
        }

        .integration-stat p {
            margin: 0;
        }

        .integration-stat .label {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .55px;
            margin-bottom: 8px;
        }

        .integration-stat .value {
            font-size: 30px;
            font-weight: 800;
            font-family: "Manrope", sans-serif;
        }

        .provider-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .provider-card {
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(20, 33, 58, 0.9) 0%, rgba(17, 29, 52, 0.82) 100%);
            display: grid;
            gap: 8px;
        }

        .provider-card h3,
        .provider-card p {
            margin: 0;
        }

        .provider-card.active {
            border-color: rgba(93, 168, 255, 0.55);
            box-shadow: inset 0 0 0 1px rgba(93, 168, 255, 0.18);
        }

        .provider-card.soon {
            opacity: .6;
        }

        .provider-card .eyebrow {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .card {
            padding: 18px;
        }

        .section-title {
            margin: 0 0 6px;
            font-size: 20px;
            font-family: "Manrope", sans-serif;
        }

        @media (max-width: 980px) {
            .integration-stats,
            .provider-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const root = document.querySelector('[data-integrations-root]');

            if (!root) {
                return;
            }

            const refreshUrl = root.dataset.refreshUrl;
            const telegramPanel = root.querySelector('[data-telegram-panel]');
            const providerBadge = root.querySelector('[data-provider-telegram-status]');
            let isRefreshing = false;

            const setTelegramRequestState = (form, submitter) => {
                const button = submitter || form.querySelector('button[type="submit"]');
                const status = root.querySelector('[data-telegram-request-status]');
                const statusText = root.querySelector('[data-telegram-request-status-text]');
                const message = form.dataset.telegramSubmitMessage || 'Working on your Telegram request...';

                if (status && statusText) {
                    status.classList.add('is-active');
                    statusText.textContent = message;
                }

                if (!button) {
                    return;
                }

                button.disabled = true;
                button.classList.add('is-loading');
            };

            const updateStats = (stats) => {
                if (!stats || typeof stats !== 'object') {
                    return;
                }

                Object.entries(stats).forEach(([key, value]) => {
                    const node = root.querySelector(`[data-integration-stat="${key}"]`);

                    if (!node) {
                        return;
                    }

                    node.textContent = Number(value || 0).toLocaleString();
                });
            };

            const updateProvider = (connected) => {
                if (!providerBadge) {
                    return;
                }

                providerBadge.textContent = connected ? 'Connected' : 'Not Connected';
                providerBadge.classList.toggle('badge-ok', connected);
                providerBadge.classList.toggle('badge-muted', !connected);
            };

            const refreshIntegrations = async () => {
                if (isRefreshing || document.hidden || !refreshUrl) {
                    return;
                }

                isRefreshing = true;

                try {
                    const response = await fetch(refreshUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();

                    updateStats(payload.stats);
                    updateProvider(Boolean(payload.telegram && payload.telegram.connected));

                    if (
                        telegramPanel &&
                        payload.telegram &&
                        typeof payload.telegram.panel_html === 'string' &&
                        telegramPanel.innerHTML !== payload.telegram.panel_html
                    ) {
                        telegramPanel.innerHTML = payload.telegram.panel_html;
                    }
                } catch (error) {
                    console.debug('Integrations refresh failed.', error);
                } finally {
                    isRefreshing = false;
                }
            };

            root.addEventListener('submit', (event) => {
                const form = event.target.closest('form[data-telegram-submit]');

                if (!form) {
                    return;
                }

                setTelegramRequestState(form, event.submitter);
            });

            window.setTimeout(refreshIntegrations, 2500);
            window.setInterval(refreshIntegrations, 5000);
        })();
    </script>
@endpush

@section('content')
    <div
        data-integrations-root
        data-refresh-url="{{ route('integrations.status') }}"
    >
    <section class="integration-stats">
        <article class="panel integration-stat">
            <p class="label">Active Providers</p>
            <p class="value" data-integration-stat="activeProviders">{{ number_format($stats['activeProviders']) }}</p>
        </article>
        <article class="panel integration-stat">
            <p class="label">Linked Groups</p>
            <p class="value" data-integration-stat="linkedGroups">{{ number_format($stats['linkedGroups']) }}</p>
        </article>
        <article class="panel integration-stat">
            <p class="label">Linked Channels</p>
            <p class="value" data-integration-stat="linkedChannels">{{ number_format($stats['linkedChannels']) }}</p>
        </article>
        <article class="panel integration-stat">
            <p class="label">Ready Destinations</p>
            <p class="value" data-integration-stat="readyDestinations">{{ number_format($stats['readyDestinations']) }}</p>
        </article>
    </section>

    <section class="provider-grid">
        <article class="provider-card active">
            <span class="eyebrow">Provider</span>
            <h3>Telegram</h3>
            <p class="muted">Bot account linking, group delivery, and channel delivery targets.</p>
            @if ($telegramUserLink)
                <span class="badge badge-ok" data-provider-telegram-status>Connected</span>
            @else
                <span class="badge badge-muted" data-provider-telegram-status>Not Connected</span>
            @endif
        </article>

        <article class="provider-card soon">
            <span class="eyebrow">Soon</span>
            <h3>Slack</h3>
            <p class="muted">Workspace auth and channel targets will land here later.</p>
            <span class="badge badge-muted">Planned</span>
        </article>

        <article class="provider-card soon">
            <span class="eyebrow">Soon</span>
            <h3>Discord</h3>
            <p class="muted">Webhook-backed servers and channel routing.</p>
            <span class="badge badge-muted">Planned</span>
        </article>

        <article class="provider-card soon">
            <span class="eyebrow">Soon</span>
            <h3>Email</h3>
            <p class="muted">Digest destinations and frequency controls.</p>
            <span class="badge badge-muted">Planned</span>
        </article>
    </section>

    <section class="panel card">
        <h3 class="section-title">Telegram Setup</h3>
        <p class="muted" style="margin:0 0 14px;">
            Telegram bots cannot fetch a full list of every group or channel they belong to. We discover destinations when the bot is added, then Add claims that destination for the linked user or falls back to an explicit picker in Telegram. This page refreshes automatically while it stays open.
        </p>

        <div data-telegram-panel>
            @include('partials.telegram-linking-panel')
        </div>
    </section>
    </div>
@endsection
