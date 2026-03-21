@extends('layouts.workspace')

@section('title', 'Integrations · rss.cursor.style')
@section('page_title', 'Integrations')
@section('page_subtitle', 'Manage delivery channels, webhook targets, and provider accounts.')

@section('top_actions')
    <a href="{{ route('feeds.generator') }}" class="btn-secondary">Back to Generator</a>
@endsection

@push('styles')
    <style>
        /* ── Stats row ─────────────────────── */
        .integration-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .integration-stat { padding: 16px; }
        .integration-stat p { margin: 0; }

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

        /* ── Tabs ──────────────────────────── */
        .intg-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 0;
            flex-wrap: wrap;
        }

        .intg-tab {
            position: relative;
            border: none;
            background: none;
            color: var(--muted);
            font-family: "Manrope", sans-serif;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 18px;
            cursor: pointer;
            white-space: nowrap;
            transition: color .2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .intg-tab::after {
            content: '';
            position: absolute;
            left: 18px;
            right: 18px;
            bottom: -1px;
            height: 2px;
            border-radius: 2px 2px 0 0;
            background: transparent;
            transition: background .2s ease;
        }

        .intg-tab:hover { color: var(--text); }

        .intg-tab.active {
            color: var(--brand);
        }

        .intg-tab.active::after {
            background: var(--brand);
        }

        .intg-tab .tab-count {
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 1px 7px;
            border: 1px solid var(--line);
            color: var(--muted);
            background: rgba(23, 39, 68, 0.5);
            min-width: 20px;
            text-align: center;
        }

        .intg-tab.active .tab-count {
            border-color: rgba(93, 168, 255, 0.4);
            color: var(--brand);
            background: rgba(93, 168, 255, 0.1);
        }

        /* ── Tab panels ────────────────────── */
        .intg-panel {
            display: none;
            animation: intgFadeIn .25s ease;
        }

        .intg-panel.active { display: block; }

        @keyframes intgFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Shared section card ───────────── */
        .intg-section {
            padding: 20px;
            margin-bottom: 14px;
        }

        .intg-section-title {
            margin: 0 0 4px;
            font-size: 18px;
            font-family: "Manrope", sans-serif;
        }

        .intg-section-desc {
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        /* ── Webhook item list ─────────────── */
        .webhook-list {
            display: grid;
            gap: 8px;
        }

        .webhook-item {
            border: 1px solid var(--line);
            border-radius: 13px;
            background: rgba(10, 20, 38, 0.75);
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: intgFadeIn .22s ease;
        }

        .webhook-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            flex: 0 0 auto;
        }

        .webhook-icon.slack    { background: rgba(74, 21, 75, 0.35); color: #e8a6e9; }
        .webhook-icon.discord  { background: rgba(88, 101, 242, 0.2); color: #a4adff; }
        .webhook-icon.teams    { background: rgba(98, 100, 167, 0.2); color: #b3b5ff; }
        .webhook-icon.email    { background: rgba(239, 143, 78, 0.15); color: #efb27e; }

        .webhook-copy {
            flex: 1;
            min-width: 0;
        }

        .webhook-copy strong {
            display: block;
            margin-bottom: 2px;
            word-break: break-all;
        }

        .webhook-copy .webhook-url-preview {
            color: var(--muted);
            font-size: 12px;
            word-break: break-all;
            display: block;
        }

        .webhook-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-shrink: 0;
        }

        /* ── Add-webhook form ──────────────── */
        .webhook-add-form {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .webhook-add-row {
            display: flex;
            gap: 10px;
            align-items: end;
        }

        .webhook-add-row .field { flex: 1; }

        /* ── Empty state ───────────────────── */
        .intg-empty {
            border: 1px dashed var(--line);
            border-radius: 14px;
            padding: 28px 16px;
            color: var(--muted);
            text-align: center;
            background: rgba(11, 20, 37, 0.5);
            font-size: 14px;
            line-height: 1.55;
        }

        /* ── Nice note block ───────────────── */
        .intg-note {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            color: var(--muted);
            background: rgba(10, 20, 38, 0.45);
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .intg-note strong { color: var(--text); }

        /* ── Modal ─────────────────────────── */
        .intg-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(5, 10, 20, 0.72);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .22s ease;
        }

        .intg-modal-backdrop.open {
            opacity: 1;
            pointer-events: auto;
        }

        .intg-modal {
            background: linear-gradient(180deg, #141f3a 0%, #111d34 100%);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 24px 64px rgba(5, 10, 20, 0.6);
            max-width: 520px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            padding: 24px;
            transform: translateY(12px) scale(0.97);
            transition: transform .22s ease;
        }

        .intg-modal-backdrop.open .intg-modal {
            transform: translateY(0) scale(1);
        }

        .intg-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .intg-modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-family: "Manrope", sans-serif;
        }

        .intg-modal-close {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(23, 39, 68, 0.6);
            color: var(--muted);
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            font-family: inherit;
            transition: border-color .15s;
        }

        .intg-modal-close:hover { border-color: var(--brand); color: var(--text); }

        .intg-modal-body { display: grid; gap: 14px; }

        .intg-modal-body .field-label { margin-bottom: 4px; }

        .intg-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 8px;
        }

        /* ── Steps (reused from telegram) ──── */
        .intg-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            margin-bottom: 14px;
        }

        .intg-step {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 12px;
            background: rgba(10, 20, 38, 0.7);
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .intg-step.is-ready {
            border-color: rgba(34, 197, 94, 0.4);
            background: rgba(34, 197, 94, 0.1);
            color: #aef1c4;
        }

        @media (max-width: 980px) {
            .integration-stats { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 640px) {
            .integration-stats { grid-template-columns: 1fr; }
            .intg-tabs { gap: 0; }
            .intg-tab { padding: 10px 12px; font-size: 13px; }
            .webhook-item { flex-wrap: wrap; }
            .webhook-actions { width: 100%; justify-content: flex-end; }
        }

        /* ── Telegram connect modal ────────── */
        .tg-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(4, 8, 18, 0.82);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            pointer-events: none;
            transition: opacity .22s ease;
        }

        .tg-modal-backdrop.open {
            opacity: 1;
            pointer-events: auto;
        }

        .tg-modal-dialog {
            background: linear-gradient(180deg, #131e36 0%, #0f1930 100%);
            border: 1px solid var(--line);
            border-radius: 22px;
            width: 100%;
            max-width: 430px;
            padding: 28px;
            box-shadow: 0 32px 80px rgba(4, 8, 18, .72);
            transform: translateY(12px) scale(.97);
            transition: transform .22s ease;
        }

        .tg-modal-backdrop.open .tg-modal-dialog {
            transform: none;
        }

        .tg-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
        }

        .tg-modal-title {
            font-size: 18px;
            font-weight: 800;
            font-family: 'Manrope', sans-serif;
            margin: 0;
        }

        .tg-modal-x {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: rgba(23, 39, 68, .65);
            border: 1px solid var(--line);
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            font-family: inherit;
            line-height: 1;
            transition: border-color .15s, color .15s;
        }

        .tg-modal-x:hover { border-color: var(--brand); color: var(--text); }

        /* Step track */
        .tg-steps-track {
            display: flex;
            align-items: center;
            margin-bottom: 28px;
        }

        .tg-step-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .tg-node-circle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 2px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            color: var(--muted);
            background: rgba(23, 39, 68, .5);
            transition: all .25s ease;
        }

        .tg-step-node.active .tg-node-circle {
            border-color: var(--brand);
            color: var(--brand);
            background: rgba(93, 168, 255, .12);
        }

        .tg-step-node.done .tg-node-circle {
            border-color: #22c55e;
            background: rgba(34, 197, 94, .15);
            color: #4ade80;
        }

        .tg-node-label {
            font-size: 10px;
            letter-spacing: .4px;
            color: var(--muted);
            text-transform: uppercase;
            white-space: nowrap;
        }

        .tg-step-node.active .tg-node-label,
        .tg-step-node.done .tg-node-label { color: var(--text); }

        .tg-step-line {
            flex: 1;
            height: 2px;
            background: var(--line);
            margin: 0 8px;
            margin-bottom: 22px;
            border-radius: 2px;
            transition: background .3s ease;
        }

        .tg-step-line.done { background: #22c55e; }

        /* Panels */
        .tg-modal-panel { display: grid; gap: 18px; }

        .tg-modal-desc {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
            margin: 0;
        }

        .tg-modal-desc strong { color: var(--text); }

        .tg-modal-foot {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        /* Waiting state */
        .tg-waiting-row {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .tg-waiting-ring {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 3px solid rgba(93, 168, 255, .18);
            border-top-color: var(--brand);
            animation: tg-spin 1s linear infinite;
            flex: 0 0 auto;
        }

        .tg-waiting-label {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 600;
        }

        .tg-waiting-hint {
            margin: 0;
            font-size: 12px;
            color: var(--muted);
        }

        /* Success state */
        .tg-success-row {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .tg-success-check {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(34, 197, 94, .14);
            border: 2px solid rgba(34, 197, 94, .5);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #4ade80;
            flex: 0 0 auto;
            animation: tg-pop .4s ease;
        }

        .tg-success-label {
            margin: 0 0 4px;
            font-size: 18px;
            font-weight: 800;
            font-family: 'Manrope', sans-serif;
            color: #4ade80;
        }

        .tg-success-sub {
            margin: 0;
            font-size: 13px;
            color: var(--muted);
        }

        @keyframes tg-spin { to { transform: rotate(360deg); } }

        @keyframes tg-pop {
            from { transform: scale(.4); opacity: 0; }
            70%  { transform: scale(1.15); }
            to   { transform: scale(1); opacity: 1; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const root = document.querySelector('[data-integrations-root]');
            if (!root) return;

            const refreshUrl = root.dataset.refreshUrl;
            const telegramPanel = root.querySelector('[data-telegram-panel]');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let isRefreshing = false;

            /* ── Tab switching ─────────────────── */
            const tabs = root.querySelectorAll('[data-intg-tab]');
            const panels = root.querySelectorAll('[data-intg-panel]');

            const activateTab = (id) => {
                tabs.forEach(t => t.classList.toggle('active', t.dataset.intgTab === id));
                panels.forEach(p => {
                    const wasActive = p.classList.contains('active');
                    const shouldBeActive = p.dataset.intgPanel === id;
                    p.classList.toggle('active', shouldBeActive);
                    if (shouldBeActive && !wasActive) {
                        p.style.animation = 'none';
                        p.offsetHeight; // reflow
                        p.style.animation = '';
                    }
                });
                history.replaceState(null, '', '#' + id);
            };

            tabs.forEach(tab => {
                tab.addEventListener('click', () => activateTab(tab.dataset.intgTab));
            });

            // Restore tab from hash
            const hashTab = location.hash.replace('#', '');
            if (hashTab && root.querySelector(`[data-intg-tab="${hashTab}"]`)) {
                activateTab(hashTab);
            }

            /* ── Modal management ──────────────── */
            const openModal = (id) => {
                const backdrop = root.querySelector(`[data-modal="${id}"]`);
                if (backdrop) {
                    backdrop.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
            };

            const closeModal = (id) => {
                const backdrop = root.querySelector(`[data-modal="${id}"]`);
                if (backdrop) {
                    backdrop.classList.remove('open');
                    document.body.style.overflow = '';
                }
            };

            root.querySelectorAll('[data-open-modal]').forEach(btn => {
                btn.addEventListener('click', () => openModal(btn.dataset.openModal));
            });

            root.querySelectorAll('[data-close-modal]').forEach(btn => {
                btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
            });

            root.querySelectorAll('.intg-modal-backdrop').forEach(backdrop => {
                backdrop.addEventListener('click', (e) => {
                    if (e.target === backdrop) closeModal(backdrop.dataset.modal);
                });
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    root.querySelectorAll('.intg-modal-backdrop.open').forEach(b => {
                        closeModal(b.dataset.modal);
                    });
                }
            });

            /* ── Stats refresh ─────────────────── */
            const updateStats = (stats) => {
                if (!stats || typeof stats !== 'object') return;
                Object.entries(stats).forEach(([key, value]) => {
                    const node = root.querySelector(`[data-integration-stat="${key}"]`);
                    if (node) node.textContent = Number(value || 0).toLocaleString();
                });
            };

            const updateProvider = (connected) => {
                // status is now shown via dot in the Telegram panel (refreshed via panel_html)
            };

            const refreshIntegrations = async () => {
                if (isRefreshing || document.hidden || !refreshUrl) return;
                isRefreshing = true;
                try {
                    const res = await fetch(refreshUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) return;
                    const payload = await res.json();
                    updateStats(payload.stats);
                    updateProvider(Boolean(payload.telegram?.connected));
                    if (telegramPanel && payload.telegram?.panel_html && telegramPanel.innerHTML !== payload.telegram.panel_html) {
                        telegramPanel.innerHTML = payload.telegram.panel_html;
                    }
                } catch (e) {
                    console.debug('Integrations refresh failed.', e);
                } finally {
                    isRefreshing = false;
                }
            };

            /* ── Telegram form handling ────────── */
            const setTelegramRequestState = (form, submitter) => {
                const button = submitter || form.querySelector('button[type="submit"]');
                const status = root.querySelector('[data-telegram-request-status]');
                const statusText = root.querySelector('[data-telegram-request-status-text]');
                const message = form.dataset.telegramSubmitMessage || 'Working on your Telegram request...';
                if (status && statusText) { status.classList.add('is-active'); statusText.textContent = message; }
                if (button) { button.disabled = true; button.classList.add('is-loading'); }
            };

            root.addEventListener('submit', (event) => {
                const form = event.target.closest('form[data-telegram-submit]');
                if (!form) return;

                if (form.hasAttribute('data-telegram-connect')) {
                    event.preventDefault();
                    const button = event.submitter || form.querySelector('button[type="submit"]');
                    if (button) { button.disabled = true; button.classList.add('is-loading'); }

                    fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: new FormData(form),
                    })
                    .then(r => r.json())
                    .then(p => {
                        if (p.url) window.open(p.url, '_blank', 'noopener,noreferrer');
                        else if (p.error) alert(p.error);
                    })
                    .catch(() => alert('Could not generate connect link. Please try again.'))
                    .finally(() => { if (button) { button.disabled = false; button.classList.remove('is-loading'); } });
                    return;
                }

                setTelegramRequestState(form, event.submitter);
            });

            /* ── Webhook add forms ─────────────── */
            root.querySelectorAll('[data-webhook-add-form]').forEach(form => {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const channel = form.dataset.webhookChannel;
                    const input = form.querySelector('[data-webhook-url-input]');
                    const labelInput = form.querySelector('[data-webhook-label-input]');
                    const btn = form.querySelector('button[type="submit"]');
                    const url = input?.value?.trim() || '';
                    const label = labelInput?.value?.trim() || '';

                    if (!url) { input?.focus(); return; }
                    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

                    try {
                        const res = await fetch('{{ route("integrations.webhooks.store") }}', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({ channel, webhook_url: url, label }),
                        });
                        const data = await res.json();
                        if (!res.ok) {
                            alert(data.message || data.errors?.webhook_url?.[0] || 'Failed to save webhook.');
                            return;
                        }
                        // Reload the page to show the new webhook
                        location.reload();
                    } catch {
                        alert('Network error. Please try again.');
                    } finally {
                        if (btn) { btn.disabled = false; btn.textContent = 'Add'; }
                    }
                });
            });

            /* ── Webhook delete ────────────────── */
            root.addEventListener('click', async (e) => {
                const btn = e.target.closest('[data-webhook-delete]');
                if (!btn) return;

                if (!confirm('Remove this webhook? Feeds using it will stop delivering.')) return;
                const webhookId = btn.dataset.webhookDelete;
                btn.disabled = true;

                try {
                    const res = await fetch(`/integrations/webhooks/${webhookId}`, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });
                    if (res.ok) location.reload();
                    else alert('Failed to remove webhook.');
                } catch {
                    alert('Network error.');
                } finally {
                    btn.disabled = false;
                }
            });

            setTimeout(refreshIntegrations, 2500);
            setInterval(refreshIntegrations, 5000);

            /* ── Telegram connect modal ────────────── */
            (() => {
                const modal  = document.getElementById('tg-connect-modal');
                if (!modal) return;

                let pollTimer     = null;
                let lastBotUrl    = null;
                let modalIsOpen   = false;

                const setStep = (n) => {
                    [1, 2, 3].forEach(i => {
                        const el = document.getElementById(`tg-panel-${i}`);
                        if (el) el.style.display = i === n ? '' : 'none';
                    });
                    modal.querySelectorAll('[data-node]').forEach(node => {
                        const ni = parseInt(node.dataset.node);
                        node.classList.toggle('active', ni === n);
                        node.classList.toggle('done',   ni <  n);
                    });
                    const l1 = document.getElementById('tg-line-1');
                    const l2 = document.getElementById('tg-line-2');
                    if (l1) l1.classList.toggle('done', n > 1);
                    if (l2) l2.classList.toggle('done', n > 2);
                };

                const openModal = () => {
                    modal.classList.add('open');
                    document.body.style.overflow = 'hidden';
                    modalIsOpen = true;
                    setStep(1);
                    lastBotUrl = null;
                    const btn = document.getElementById('tg-open-btn');
                    const txt = document.getElementById('tg-open-btn-text');
                    if (btn) btn.disabled = false;
                    if (txt) txt.innerHTML = 'Open in Telegram &nearr;';
                };

                const closeModal = () => {
                    modal.classList.remove('open');
                    document.body.style.overflow = '';
                    modalIsOpen = false;
                    stopPolling();
                };

                const stopPolling = () => {
                    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                };

                const startPolling = () => {
                    stopPolling();
                    pollTimer = setInterval(async () => {
                        try {
                            const res = await fetch(refreshUrl, {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            });
                            if (!res.ok) return;
                            const data = await res.json();
                            updateStats(data.stats);
                            if (data.telegram?.connected) {
                                stopPolling();
                                if (telegramPanel && data.telegram.panel_html) {
                                    telegramPanel.innerHTML = data.telegram.panel_html;
                                }
                                const handle = data.telegram.handle || 'your account';
                                const sub = document.getElementById('tg-success-handle');
                                if (sub) sub.textContent = `Connected as ${handle}`;
                                setStep(3);
                                setTimeout(closeModal, 2500);
                            }
                        } catch { /* silent */ }
                    }, 2500);
                };

                // Delegate: Connect button may live inside refreshed panel_html
                document.addEventListener('click', (e) => {
                    if (e.target.closest('[data-tg-connect-trigger]')) openModal();
                });

                modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
                document.getElementById('tg-modal-close')?.addEventListener('click', closeModal);
                document.getElementById('tg-cancel-1')?.addEventListener('click', closeModal);
                document.getElementById('tg-cancel-2')?.addEventListener('click', closeModal);
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && modalIsOpen) closeModal();
                });

                document.getElementById('tg-open-btn')?.addEventListener('click', async () => {
                    const btn = document.getElementById('tg-open-btn');
                    const txt = document.getElementById('tg-open-btn-text');
                    if (btn) btn.disabled = true;
                    if (txt) txt.textContent = 'Opening\u2026';
                    try {
                        const res = await fetch('{{ route("telegram.connect") }}', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const data = await res.json();
                        if (data.url) {
                            lastBotUrl = data.url;
                            window.open(lastBotUrl, '_blank', 'noopener,noreferrer');
                            setStep(2);
                            startPolling();
                        } else {
                            throw new Error(data.error || 'Could not generate link.');
                        }
                    } catch (err) {
                        if (btn) btn.disabled = false;
                        if (txt) txt.innerHTML = 'Open in Telegram &nearr;';
                        alert(err.message || 'Could not generate link. Please try again.');
                    }
                });

                document.getElementById('tg-reopen-btn')?.addEventListener('click', () => {
                    if (lastBotUrl) window.open(lastBotUrl, '_blank', 'noopener,noreferrer');
                });
            })();

        })();
    </script>
@endpush

@section('content')
    <div data-integrations-root data-refresh-url="{{ route('integrations.status') }}">

    {{-- ── Stats ──────────────────────────── --}}
    <section class="integration-stats">
        <article class="panel integration-stat">
            <p class="label">Active Providers</p>
            <p class="value" data-integration-stat="activeProviders">{{ number_format($stats['activeProviders']) }}</p>
        </article>
        <article class="panel integration-stat">
            <p class="label">Telegram Destinations</p>
            <p class="value" data-integration-stat="linkedGroups">{{ number_format($stats['linkedGroups'] + $stats['linkedChannels']) }}</p>
        </article>
        <article class="panel integration-stat">
            <p class="label">Webhooks</p>
            <p class="value" data-integration-stat="webhookCount">{{ number_format($webhookCounts->sum()) }}</p>
        </article>
        <article class="panel integration-stat">
            <p class="label">Ready Destinations</p>
            <p class="value" data-integration-stat="readyDestinations">{{ number_format($stats['readyDestinations']) }}</p>
        </article>
    </section>

    {{-- ── Tabs ───────────────────────────── --}}
    <nav class="intg-tabs">
        <button class="intg-tab active" data-intg-tab="telegram">
            Telegram
            <span class="tab-count">{{ $telegramGroupChats->count() + $telegramChannelChats->count() }}</span>
        </button>
        <button class="intg-tab" data-intg-tab="slack">
            Slack
            <span class="tab-count">{{ $webhookCounts->get('slack', 0) }}</span>
        </button>
        <button class="intg-tab" data-intg-tab="discord">
            Discord
            <span class="tab-count">{{ $webhookCounts->get('discord', 0) }}</span>
        </button>
        <button class="intg-tab" data-intg-tab="teams">
            Teams
            <span class="tab-count">{{ $webhookCounts->get('teams', 0) }}</span>
        </button>
        <button class="intg-tab" data-intg-tab="email">
            Email
            <span class="tab-count">{{ $emailCount }}</span>
        </button>
    </nav>

    {{-- ══════════════════════════════════════
         TAB: Telegram
         ══════════════════════════════════════ --}}
    <div class="intg-panel active" data-intg-panel="telegram">
        <section class="panel intg-section" data-telegram-panel>
            @include('partials.telegram-linking-panel')
        </section>

        {{-- Telegram connect modal – static, not refreshed with panel_html --}}
        <div class="tg-modal-backdrop" id="tg-connect-modal" role="dialog" aria-modal="true" aria-labelledby="tg-modal-heading">
            <div class="tg-modal-dialog">
                <div class="tg-modal-head">
                    <h2 class="tg-modal-title" id="tg-modal-heading">Connect Telegram</h2>
                    <button class="tg-modal-x" id="tg-modal-close" aria-label="Close">&times;</button>
                </div>

                <div class="tg-steps-track">
                    <div class="tg-step-node active" data-node="1">
                        <div class="tg-node-circle">1</div>
                        <div class="tg-node-label">Open bot</div>
                    </div>
                    <div class="tg-step-line" id="tg-line-1"></div>
                    <div class="tg-step-node" data-node="2">
                        <div class="tg-node-circle">2</div>
                        <div class="tg-node-label">Confirm</div>
                    </div>
                    <div class="tg-step-line" id="tg-line-2"></div>
                    <div class="tg-step-node" data-node="3">
                        <div class="tg-node-circle">3</div>
                        <div class="tg-node-label">Done</div>
                    </div>
                </div>

                {{-- Step 1 --}}
                <div id="tg-panel-1" class="tg-modal-panel">
                    <p class="tg-modal-desc">Open the Telegram bot and press <strong>Start</strong> to link your account. Keep this page open &mdash; it updates automatically.</p>
                    <div class="tg-modal-foot">
                        <button class="btn-secondary" id="tg-cancel-1">Cancel</button>
                        <button class="btn" id="tg-open-btn">
                            <span id="tg-open-btn-text">Open in Telegram &nearr;</span>
                        </button>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div id="tg-panel-2" class="tg-modal-panel" style="display:none">
                    <div class="tg-waiting-row">
                        <div class="tg-waiting-ring"></div>
                        <div>
                            <p class="tg-waiting-label">Waiting for confirmation&hellip;</p>
                            <p class="tg-waiting-hint">Checking every few seconds &mdash; no need to refresh.</p>
                        </div>
                    </div>
                    <div class="tg-modal-foot">
                        <button class="btn-secondary" id="tg-cancel-2">Cancel</button>
                        <button class="btn-secondary" id="tg-reopen-btn">Open Again &nearr;</button>
                    </div>
                </div>

                {{-- Step 3 --}}
                <div id="tg-panel-3" class="tg-modal-panel" style="display:none">
                    <div class="tg-success-row">
                        <div class="tg-success-check">&#10003;</div>
                        <div>
                            <p class="tg-success-label">Connected!</p>
                            <p class="tg-success-sub" id="tg-success-handle">Closing&hellip;</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         TAB: Slack
         ══════════════════════════════════════ --}}
    <div class="intg-panel" data-intg-panel="slack">
        <section class="panel intg-section">
            <h3 class="intg-section-title">Slack Webhooks</h3>
            <p class="intg-section-desc">Add Slack Incoming Webhook URLs to receive feed updates in your Slack channels. You can create webhooks in <a href="https://api.slack.com/apps" target="_blank" rel="noopener" style="color:var(--brand);">Slack App Settings</a> &rarr; Incoming Webhooks.</p>

            <div class="intg-steps">
                <div class="intg-step">
                    1. Create a Slack app (or use an existing one) at <strong style="color:var(--text);">api.slack.com/apps</strong>.
                </div>
                <div class="intg-step">
                    2. Enable Incoming Webhooks and create a new webhook URL for the target channel.
                </div>
                <div class="intg-step">
                    3. Paste the webhook URL below. It starts with <code style="font-size:11px;">https://hooks.slack.com/</code>.
                </div>
            </div>

            @include('integrations.partials.webhook-list', ['channel' => 'slack', 'icon' => 'S', 'webhooks' => $slackWebhooks ?? collect()])

            <form data-webhook-add-form data-webhook-channel="slack" class="webhook-add-form">
                <div class="webhook-add-row">
                    <div class="field">
                        <label class="field-label">Webhook URL</label>
                        <input type="url" data-webhook-url-input placeholder="https://hooks.slack.com/services/T.../B.../..." required>
                    </div>
                    <button type="submit" class="btn" style="height:44px;">Add</button>
                </div>
                <div class="field">
                    <label class="field-label">Label <span style="color:var(--muted); font-weight:400;">(optional)</span></label>
                    <input type="text" data-webhook-label-input placeholder="e.g. #engineering-news" maxlength="100">
                </div>
            </form>
        </section>
    </div>

    {{-- ══════════════════════════════════════
         TAB: Discord
         ══════════════════════════════════════ --}}
    <div class="intg-panel" data-intg-panel="discord">
        <section class="panel intg-section">
            <h3 class="intg-section-title">Discord Webhooks</h3>
            <p class="intg-section-desc">Add Discord webhook URLs to deliver feed updates to your server channels. Create one in Discord &rarr; Server Settings &rarr; Integrations &rarr; Webhooks.</p>

            <div class="intg-steps">
                <div class="intg-step">
                    1. Open your Discord server and go to <strong style="color:var(--text);">Server Settings &rarr; Integrations</strong>.
                </div>
                <div class="intg-step">
                    2. Create a new Webhook and copy the URL.
                </div>
                <div class="intg-step">
                    3. Paste it below. It starts with <code style="font-size:11px;">https://discord.com/api/webhooks/</code>.
                </div>
            </div>

            @include('integrations.partials.webhook-list', ['channel' => 'discord', 'icon' => 'D', 'webhooks' => $discordWebhooks ?? collect()])

            <form data-webhook-add-form data-webhook-channel="discord" class="webhook-add-form">
                <div class="webhook-add-row">
                    <div class="field">
                        <label class="field-label">Webhook URL</label>
                        <input type="url" data-webhook-url-input placeholder="https://discord.com/api/webhooks/123.../abc..." required>
                    </div>
                    <button type="submit" class="btn" style="height:44px;">Add</button>
                </div>
                <div class="field">
                    <label class="field-label">Label <span style="color:var(--muted); font-weight:400;">(optional)</span></label>
                    <input type="text" data-webhook-label-input placeholder="e.g. #tech-updates" maxlength="100">
                </div>
            </form>
        </section>
    </div>

    {{-- ══════════════════════════════════════
         TAB: Teams
         ══════════════════════════════════════ --}}
    <div class="intg-panel" data-intg-panel="teams">
        <section class="panel intg-section">
            <h3 class="intg-section-title">Microsoft Teams Webhooks</h3>
            <p class="intg-section-desc">Add Teams Workflow webhook URLs. In Teams, create a Workflow (Power Automate) that posts to a channel, then paste the webhook URL here.</p>

            <div class="intg-steps">
                <div class="intg-step">
                    1. In Microsoft Teams, open the target channel and go to <strong style="color:var(--text);">Workflows</strong>.
                </div>
                <div class="intg-step">
                    2. Create a "Post to a channel when a webhook request is received" workflow.
                </div>
                <div class="intg-step">
                    3. Copy the generated URL and paste it below.
                </div>
            </div>

            @include('integrations.partials.webhook-list', ['channel' => 'teams', 'icon' => 'T', 'webhooks' => $teamsWebhooks ?? collect()])

            <form data-webhook-add-form data-webhook-channel="teams" class="webhook-add-form">
                <div class="webhook-add-row">
                    <div class="field">
                        <label class="field-label">Webhook URL</label>
                        <input type="url" data-webhook-url-input placeholder="https://prod-XX.logic.azure.com/workflows/..." required>
                    </div>
                    <button type="submit" class="btn" style="height:44px;">Add</button>
                </div>
                <div class="field">
                    <label class="field-label">Label <span style="color:var(--muted); font-weight:400;">(optional)</span></label>
                    <input type="text" data-webhook-label-input placeholder="e.g. Engineering Team" maxlength="100">
                </div>
            </form>
        </section>
    </div>

    {{-- ══════════════════════════════════════
         TAB: Email
         ══════════════════════════════════════ --}}
    <div class="intg-panel" data-intg-panel="email">
        <section class="panel intg-section">
            <h3 class="intg-section-title">Email Digest</h3>
            <p class="intg-section-desc">Add email addresses to receive a daily digest of new articles from your subscribed feeds.</p>

            @include('integrations.partials.webhook-list', ['channel' => 'email', 'icon' => '@', 'webhooks' => $emailWebhooks ?? collect()])

            <form data-webhook-add-form data-webhook-channel="email" class="webhook-add-form">
                <div class="webhook-add-row">
                    <div class="field">
                        <label class="field-label">Email address</label>
                        <input type="email" data-webhook-url-input placeholder="you@example.com" required>
                    </div>
                    <button type="submit" class="btn" style="height:44px;">Add</button>
                </div>
                <div class="field">
                    <label class="field-label">Label <span style="color:var(--muted); font-weight:400;">(optional)</span></label>
                    <input type="text" data-webhook-label-input placeholder="e.g. Personal digest" maxlength="100">
                </div>
            </form>
        </section>
    </div>

    </div>
@endsection
