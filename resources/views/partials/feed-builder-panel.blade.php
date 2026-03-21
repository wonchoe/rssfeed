@php
    $feedBuilderRedirectTo = $feedBuilderRedirectTo ?? route('dashboard', absolute: false);
    $feedBuilderCreatedFrom = $feedBuilderCreatedFrom ?? 'dashboard_builder';
    $telegramGroupChats = $telegramGroupChats ?? collect();
    $telegramChannelChats = $telegramChannelChats ?? collect();
    $webhookIntegrations = $webhookIntegrations ?? collect();
    $defaultDestinationTab = $telegramGroupChats->isNotEmpty() ? 'group' : 'channel';
@endphp

@once
    @push('styles')
        <style>
            .feed-builder-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
                gap: 14px;
                align-items: start;
            }

            .feed-builder-card {
                padding: 18px;
            }

            .feed-builder-title {
                margin: 0 0 10px;
                font-size: 22px;
                font-family: "Manrope", sans-serif;
            }

            .feed-builder-toolbar {
                display: flex;
                align-items: end;
                gap: 10px;
                flex-wrap: wrap;
                margin-bottom: 12px;
            }

            .feed-builder-toolbar .field {
                flex: 1;
                min-width: 240px;
            }

            .feed-builder-loader {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: var(--muted);
                font-size: 13px;
            }

            .feed-builder-dot-loader {
                width: 8px;
                height: 8px;
                border-radius: 999px;
                background: var(--accent);
                box-shadow: 14px 0 0 rgba(239, 143, 78, 0.5), 28px 0 0 rgba(239, 143, 78, 0.25);
                animation: feed-builder-pulse 1s infinite linear;
            }

            @keyframes feed-builder-pulse {
                0% { transform: translateX(0); opacity: .85; }
                50% { transform: translateX(7px); opacity: 1; }
                100% { transform: translateX(0); opacity: .85; }
            }

            .feed-builder-status {
                min-height: 18px;
                font-size: 12px;
                color: var(--muted);
            }

            .feed-builder-mode-switch {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 8px 0 0;
                flex-wrap: wrap;
            }

            .feed-builder-mode-btn {
                border: 1px solid var(--line);
                border-radius: 999px;
                padding: 5px 10px;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: .3px;
                color: var(--muted);
                background: rgba(13, 24, 45, 0.7);
                cursor: pointer;
            }

            .feed-builder-mode-btn.active {
                color: #0f203d;
                background: linear-gradient(135deg, #66b2ff 0%, #8ac6ff 100%);
                border-color: transparent;
            }

            .feed-builder-meta {
                margin-top: 8px;
                border: 1px solid var(--line);
                border-radius: 12px;
                background: rgba(9, 18, 34, 0.75);
                padding: 10px 12px;
                font-size: 12px;
                color: var(--muted);
                display: grid;
                gap: 5px;
            }

            .feed-builder-preview-list {
                display: grid;
                gap: 10px;
                margin-top: 14px;
            }

            .feed-builder-preview-item {
                border: 1px solid var(--line);
                background: rgba(10, 20, 38, 0.8);
                border-radius: 14px;
                padding: 12px;
                display: grid;
                gap: 8px;
                transform: translateY(4px);
                opacity: 0;
                animation: feed-builder-reveal .28s ease forwards;
            }

            @keyframes feed-builder-reveal {
                to { transform: translateY(0); opacity: 1; }
            }

            .feed-builder-preview-item h4 {
                margin: 0;
                font-size: 16px;
                line-height: 1.4;
            }

            .feed-builder-preview-item p {
                margin: 0;
                color: var(--muted);
                font-size: 13px;
                line-height: 1.5;
            }

            .feed-builder-preview-item a {
                color: #9bc8ff;
                font-size: 12px;
                word-break: break-all;
            }

            .feed-builder-preview-cover {
                max-width: 360px;
                border-radius: 10px;
                object-fit: cover;
                border: 1px solid var(--line);
                background: rgba(8, 16, 30, 0.7);
            }

            .feed-builder-preview-list.mode-list {
                gap: 8px;
            }

            .feed-builder-preview-list.mode-list .feed-builder-preview-item {
                border-radius: 11px;
                padding: 10px;
            }

            .feed-builder-preview-list.mode-list .feed-builder-preview-item h4 {
                font-size: 15px;
            }

            .feed-builder-preview-list.mode-compact {
                gap: 6px;
            }

            .feed-builder-preview-list.mode-compact .feed-builder-preview-item {
                border-radius: 10px;
                padding: 9px 10px;
                background: rgba(9, 18, 34, 0.72);
            }

            .feed-builder-preview-list.mode-compact .feed-builder-preview-item h4 {
                font-size: 14px;
            }

            .feed-builder-preview-list.mode-compact .summary,
            .feed-builder-preview-list.mode-compact .published {
                display: none;
            }

            .feed-builder-empty {
                border: 1px dashed var(--line);
                border-radius: 14px;
                padding: 24px 16px;
                color: var(--muted);
                text-align: center;
                background: rgba(11, 20, 37, 0.62);
            }

            .feed-destination-box {
                display: grid;
                gap: 12px;
            }

            .feed-destination-box .hint {
                margin: 0;
                color: var(--muted);
                font-size: 13px;
                line-height: 1.5;
            }

            .feed-destination-intro {
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 12px;
                background: rgba(10, 20, 38, 0.78);
                color: var(--muted);
                font-size: 13px;
                line-height: 1.5;
            }

            .feed-destination-tabs {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .feed-destination-tab {
                border: 1px solid var(--line);
                border-radius: 999px;
                padding: 7px 12px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: .3px;
                background: rgba(15, 24, 45, 0.65);
                color: var(--muted);
                cursor: pointer;
            }

            .feed-destination-tab.active {
                color: #0f203d;
                border-color: transparent;
                background: linear-gradient(135deg, #66b2ff 0%, #8ac6ff 100%);
            }

            .feed-destination-panel {
                display: grid;
                gap: 10px;
            }

            .feed-destination-panel[hidden] {
                display: none;
            }

            .feed-destination-list {
                display: grid;
                gap: 8px;
            }

            .feed-destination-choice {
                border: 1px solid var(--line);
                border-radius: 13px;
                padding: 10px 12px;
                background: rgba(10, 20, 38, 0.8);
                color: var(--text);
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                gap: 10px;
                align-items: center;
                text-align: left;
                font-family: inherit;
                cursor: pointer;
            }

            .feed-destination-choice:hover {
                border-color: rgba(93, 168, 255, 0.45);
                background: rgba(18, 31, 56, 0.88);
            }

            .feed-destination-choice.is-loading {
                opacity: .8;
                pointer-events: none;
            }

            .feed-destination-avatar {
                width: 42px;
                height: 42px;
                border-radius: 999px;
                background: linear-gradient(135deg, #5da8ff 0%, #ef8f4e 100%);
                color: #06142a;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-weight: 800;
                font-family: "Manrope", sans-serif;
                font-size: 15px;
                overflow: hidden;
                flex: 0 0 auto;
            }

            .feed-destination-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }

            .feed-destination-copy {
                min-width: 0;
                display: grid;
                gap: 4px;
            }

            .feed-destination-copy strong,
            .feed-destination-copy span {
                display: block;
            }

            .feed-destination-copy span {
                color: var(--muted);
                font-size: 12px;
            }

            .feed-destination-arrow {
                color: var(--muted);
                font-weight: 700;
            }

            .feed-destination-spinner {
                width: 14px;
                height: 14px;
                border-radius: 999px;
                border: 2px solid currentColor;
                border-right-color: transparent;
                animation: feed-destination-spin .75s linear infinite;
                display: none;
            }

            .feed-destination-choice.is-loading .feed-destination-spinner {
                display: inline-block;
            }

            @keyframes feed-destination-spin {
                to {
                    transform: rotate(360deg);
                }
            }

            /* ── Translation toggle ────────────── */
            .feed-translate-section {
                border: 1px solid var(--line);
                border-radius: 13px;
                background: rgba(10, 20, 38, 0.8);
                padding: 14px;
            }

            .feed-translate-toggle-row {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .feed-translate-label {
                font-size: 14px;
                font-weight: 600;
            }

            .feed-translate-switch {
                position: relative;
                display: inline-block;
                width: 42px;
                height: 24px;
                flex-shrink: 0;
            }

            .feed-translate-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .feed-translate-slider {
                position: absolute;
                cursor: pointer;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(255,255,255,0.1);
                border-radius: 999px;
                transition: .25s;
            }

            .feed-translate-slider::before {
                content: "";
                position: absolute;
                width: 18px;
                height: 18px;
                left: 3px;
                bottom: 3px;
                background: var(--text);
                border-radius: 999px;
                transition: .25s;
            }

            .feed-translate-switch input:checked + .feed-translate-slider {
                background: var(--brand);
            }

            .feed-translate-switch input:checked + .feed-translate-slider::before {
                transform: translateX(18px);
            }

            .feed-translate-options {
                margin-top: 12px;
                display: grid;
                gap: 8px;
            }

            .feed-translate-select {
                width: 100%;
                padding: 9px 12px;
                border-radius: 10px;
                border: 1px solid var(--line);
                background: rgba(9, 18, 34, 0.9);
                color: var(--text);
                font-family: inherit;
                font-size: 14px;
                cursor: pointer;
                -webkit-appearance: none;
                appearance: none;
            }

            .feed-translate-select:focus {
                outline: none;
                border-color: var(--brand);
            }

            .feed-translate-hint {
                margin: 0;
                font-size: 12px;
                color: var(--muted);
                line-height: 1.5;
            }

            @media (max-width: 1150px) {
                .feed-builder-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 760px) {
                .feed-destination-choice {
                    grid-template-columns: auto minmax(0, 1fr);
                }

                .feed-destination-arrow {
                    display: none;
                }
            }

            /* ── Channel selector tabs ─────────── */
            .feed-channel-tabs {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
            }

            .feed-channel-tab {
                border: 1px solid var(--line);
                border-radius: 999px;
                padding: 7px 14px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: .3px;
                background: rgba(15, 24, 45, 0.65);
                color: var(--muted);
                cursor: pointer;
                font-family: inherit;
                transition: all .18s ease;
            }

            .feed-channel-tab:hover {
                color: var(--text);
                border-color: rgba(93, 168, 255, 0.35);
            }

            .feed-channel-tab.active {
                color: #0f203d;
                border-color: transparent;
                background: linear-gradient(135deg, #66b2ff 0%, #8ac6ff 100%);
            }

            .feed-channel-panel {
                display: none;
            }

            .feed-channel-panel.active {
                display: block;
                animation: feedChannelFade .22s ease;
            }

            @keyframes feedChannelFade {
                from { opacity: 0; transform: translateY(4px); }
                to   { opacity: 1; transform: translateY(0); }
            }
        </style>
    @endpush
@endonce

<section class="feed-builder-grid">
    <article class="panel feed-builder-card">
        <h3 class="feed-builder-title">Generate Preview</h3>
        <p class="muted" style="margin:0 0 14px;">Paste a news site or feed URL and we will pull a quick preview of the latest items before you save it.</p>

        <form id="feed-builder-preview-form" class="feed-builder-toolbar" autocomplete="off">
            @csrf
            <div class="field">
                <label for="feed_builder_source_url" class="field-label">Enter news site URL</label>
                <input
                    id="feed_builder_source_url"
                    name="source_url"
                    type="text"
                    placeholder="https://example.com or direct feed URL"
                    required
                >
            </div>
            <div style="display:flex; align-items:end;">
                <button id="feed-builder-generate-btn" class="btn" type="submit">Generate</button>
            </div>
        </form>

        <div id="feed-builder-loader" class="feed-builder-loader" style="display:none; margin-bottom:10px;">
            <span class="feed-builder-dot-loader" aria-hidden="true"></span>
            <span>Generating news preview...</span>
        </div>

        <p id="feed-builder-status" class="feed-builder-status"></p>

        <div class="feed-builder-mode-switch">
            <span class="muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.45px;">View</span>
            <button type="button" class="feed-builder-mode-btn active" data-feed-preview-mode="card">Card</button>
            <button type="button" class="feed-builder-mode-btn" data-feed-preview-mode="list">List</button>
            <button type="button" class="feed-builder-mode-btn" data-feed-preview-mode="compact">Compact</button>
        </div>

        <div id="feed-builder-preview-empty" class="feed-builder-empty" style="margin-top:14px;">
            News preview will appear here after generation.
        </div>

        <div id="feed-builder-preview-result" style="display:none;">
            <div id="feed-builder-meta" class="feed-builder-meta"></div>
            <div id="feed-builder-preview-list" class="feed-builder-preview-list"></div>
        </div>
    </article>

    <article class="panel feed-builder-card">
        <h3 class="feed-builder-title">Add This Feed</h3>
        <p class="hint">If the preview looks good, choose a delivery channel and destination for this feed.</p>

        <form id="feed-builder-save-form" class="feed-destination-box" method="POST" action="{{ route('subscriptions.store') }}">
            @csrf
            <input id="feed_builder_save_source_url" type="hidden" name="source_url" value="">
            <input id="feed_builder_telegram_chat_id" type="hidden" name="telegram_chat_id" value="">
            <input id="feed_builder_channel" type="hidden" name="channel" value="telegram">
            <input id="feed_builder_target" type="hidden" name="target" value="">
            <input type="hidden" name="polling_interval_minutes" value="30">
            <input type="hidden" name="created_from" value="{{ $feedBuilderCreatedFrom }}">
            <input type="hidden" name="redirect_to" value="{{ $feedBuilderRedirectTo }}">
            <input id="feed_builder_translate_enabled" type="hidden" name="translate_enabled" value="0">
            <input id="feed_builder_translate_language" type="hidden" name="translate_language" value="">

            <div id="feed-builder-translate-section" class="feed-translate-section" style="display:none; margin-bottom: 8px;">
                <div class="feed-translate-toggle-row">
                    <label class="feed-translate-switch">
                        <input type="checkbox" id="feed_builder_translate_toggle">
                        <span class="feed-translate-slider"></span>
                    </label>
                    <span class="feed-translate-label">Translate articles</span>
                </div>
                <div id="feed-builder-translate-options" class="feed-translate-options" style="display:none;">
                    <label class="field-label" style="font-size:12px; margin-bottom:4px; display:block;">Target language</label>
                    <select id="feed_builder_translate_select" class="feed-translate-select">
                        <option value="">Choose language...</option>
                        <option value="uk">🇺🇦 Ukrainian</option>
                        <option value="en">🇬🇧 English</option>
                        <option value="es">🇪🇸 Spanish</option>
                        <option value="fr">🇫🇷 French</option>
                        <option value="de">🇩🇪 German</option>
                        <option value="it">🇮🇹 Italian</option>
                        <option value="pt">🇵🇹 Portuguese</option>
                        <option value="ja">🇯🇵 Japanese</option>
                        <option value="ko">🇰🇷 Korean</option>
                        <option value="zh">🇨🇳 Chinese</option>
                        <option value="ar">🇸🇦 Arabic</option>
                        <option value="hi">🇮🇳 Hindi</option>
                        <option value="pl">🇵🇱 Polish</option>
                        <option value="nl">🇳🇱 Dutch</option>
                        <option value="tr">🇹🇷 Turkish</option>
                        <option value="ru">🇷🇺 Russian</option>
                        <option value="sv">🇸🇪 Swedish</option>
                        <option value="da">🇩🇰 Danish</option>
                        <option value="fi">🇫🇮 Finnish</option>
                        <option value="no">🇳🇴 Norwegian</option>
                        <option value="cs">🇨🇿 Czech</option>
                        <option value="ro">🇷🇴 Romanian</option>
                        <option value="hu">🇭🇺 Hungarian</option>
                        <option value="el">🇬🇷 Greek</option>
                        <option value="th">🇹🇭 Thai</option>
                        <option value="vi">🇻🇳 Vietnamese</option>
                        <option value="id">🇮🇩 Indonesian</option>
                        <option value="he">🇮🇱 Hebrew</option>
                        <option value="bg">🇧🇬 Bulgarian</option>
                    </select>
                    <p class="feed-translate-hint">Articles will be translated via AI and delivered as links to rss.cursor.style with full translated content.</p>
                </div>
            </div>

            <div id="feed-builder-save-empty" class="feed-builder-empty">
                Generate a preview first. Once it looks right, you can add the feed to a delivery destination.
            </div>

            <div id="feed-builder-save-ready" style="display:none;">
                <div class="feed-destination-intro">
                    Preview is ready. Choose a channel and pick a destination.
                </div>

                {{-- Channel selector tabs --}}
                <div class="feed-channel-tabs" style="margin-top:12px;">
                    <button type="button" class="feed-channel-tab active" data-channel-tab="telegram">Telegram</button>
                    @if ($webhookIntegrations->where('channel', 'slack')->isNotEmpty())
                        <button type="button" class="feed-channel-tab" data-channel-tab="slack">Slack</button>
                    @endif
                    @if ($webhookIntegrations->where('channel', 'discord')->isNotEmpty())
                        <button type="button" class="feed-channel-tab" data-channel-tab="discord">Discord</button>
                    @endif
                    @if ($webhookIntegrations->where('channel', 'teams')->isNotEmpty())
                        <button type="button" class="feed-channel-tab" data-channel-tab="teams">Teams</button>
                    @endif
                    @if ($webhookIntegrations->where('channel', 'email')->isNotEmpty())
                        <button type="button" class="feed-channel-tab" data-channel-tab="email">Email</button>
                    @endif
                </div>

                {{-- Telegram panel --}}
                <div class="feed-channel-panel active" data-channel-panel="telegram" style="margin-top:12px;">
                    <div class="feed-destination-tabs" style="margin-bottom:12px;">
                        <button type="button" class="feed-destination-tab" data-destination-tab="group">Groups</button>
                        <button type="button" class="feed-destination-tab" data-destination-tab="channel">Channels</button>
                    </div>

                    <div class="feed-destination-panel" data-destination-panel="group">
                        @if ($telegramGroupChats->isEmpty())
                            <div class="feed-builder-empty" style="padding:16px 14px;">
                                No Telegram groups linked yet.
                                <a href="{{ route('integrations.index') }}#telegram" style="color:#9bc8ff;">Open Integrations</a>
                                to add one first.
                            </div>
                        @else
                            <div class="feed-destination-list">
                                @foreach ($telegramGroupChats as $telegramChat)
                                    <button
                                        type="button"
                                        class="feed-destination-choice"
                                        data-destination-choice
                                        data-telegram-chat-id="{{ $telegramChat->id }}"
                                        data-destination-name="{{ $telegramChat->displayName() }}"
                                        data-destination-channel="telegram"
                                    >
                                        <span class="feed-destination-avatar">
                                            @if ($telegramChat->avatarUrl())
                                                <img src="{{ $telegramChat->avatarUrl() }}" alt="{{ $telegramChat->displayName() }}">
                                            @else
                                                {{ $telegramChat->avatarInitial() }}
                                            @endif
                                        </span>
                                        <span class="feed-destination-copy">
                                            <strong>{{ $telegramChat->displayName() }}</strong>
                                            <span>{{ $telegramChat->kindLabel() }}</span>
                                        </span>
                                        <span class="feed-destination-arrow">
                                            <span class="feed-destination-spinner" aria-hidden="true"></span>
                                            <span>Use</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="feed-destination-panel" data-destination-panel="channel" hidden>
                        @if ($telegramChannelChats->isEmpty())
                            <div class="feed-builder-empty" style="padding:16px 14px;">
                                No Telegram channels linked yet.
                                <a href="{{ route('integrations.index') }}#telegram" style="color:#9bc8ff;">Open Integrations</a>
                                to add one first.
                            </div>
                        @else
                            <div class="feed-destination-list">
                                @foreach ($telegramChannelChats as $telegramChat)
                                    <button
                                        type="button"
                                        class="feed-destination-choice"
                                        data-destination-choice
                                        data-telegram-chat-id="{{ $telegramChat->id }}"
                                        data-destination-name="{{ $telegramChat->displayName() }}"
                                        data-destination-channel="telegram"
                                    >
                                        <span class="feed-destination-avatar">
                                            @if ($telegramChat->avatarUrl())
                                                <img src="{{ $telegramChat->avatarUrl() }}" alt="{{ $telegramChat->displayName() }}">
                                            @else
                                                {{ $telegramChat->avatarInitial() }}
                                            @endif
                                        </span>
                                        <span class="feed-destination-copy">
                                            <strong>{{ $telegramChat->displayName() }}</strong>
                                            <span>{{ $telegramChat->kindLabel() }}</span>
                                        </span>
                                        <span class="feed-destination-arrow">
                                            <span class="feed-destination-spinner" aria-hidden="true"></span>
                                            <span>Use</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Webhook/Email panels --}}
                @foreach (['slack', 'discord', 'teams', 'email'] as $wch)
                    @php $chWebhooks = $webhookIntegrations->where('channel', $wch); @endphp
                    @if ($chWebhooks->isNotEmpty())
                        <div class="feed-channel-panel" data-channel-panel="{{ $wch }}" style="margin-top:12px; display:none;">
                            <div class="feed-destination-list">
                                @foreach ($chWebhooks as $wh)
                                    <button
                                        type="button"
                                        class="feed-destination-choice"
                                        data-destination-choice
                                        data-destination-channel="{{ $wch }}"
                                        data-destination-target="{{ $wh->webhook_url }}"
                                        data-destination-name="{{ $wh->label ?: ($wch === 'email' ? $wh->webhook_url : ucfirst($wch).' Webhook') }}"
                                    >
                                        <span class="feed-destination-avatar" style="border-radius:12px; font-size:14px; background:rgba(93,168,255,0.15); color:#7bbfff;">
                                            {{ $wch === 'email' ? '@' : strtoupper(substr($wch, 0, 1)) }}
                                        </span>
                                        <span class="feed-destination-copy">
                                            <strong>{{ $wh->label ?: ($wch === 'email' ? $wh->webhook_url : ucfirst($wch).' Webhook') }}</strong>
                                            @if ($wch !== 'email')
                                                <span style="font-size:11px;">{{ Str::limit($wh->webhook_url, 50) }}</span>
                                            @endif
                                        </span>
                                        <span class="feed-destination-arrow">
                                            <span class="feed-destination-spinner" aria-hidden="true"></span>
                                            <span>Use</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                            @if ($wch !== 'email')
                                <p style="margin:10px 0 0; font-size:12px; color:var(--muted);">
                                    Manage webhooks in <a href="{{ route('integrations.index') }}#{{ $wch }}" style="color:#9bc8ff;">Integrations</a>.
                                </p>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </form>
    </article>
</section>

@once
    @push('scripts')
        <script>
            (() => {
                const previewForm = document.getElementById('feed-builder-preview-form');
                const saveForm = document.getElementById('feed-builder-save-form');

                if (!previewForm || !saveForm) {
                    return;
                }

                const generateButton = document.getElementById('feed-builder-generate-btn');
                const sourceInput = document.getElementById('feed_builder_source_url');
                const saveSourceInput = document.getElementById('feed_builder_save_source_url');
                const saveTelegramChatInput = document.getElementById('feed_builder_telegram_chat_id');
                const saveChannelInput = document.getElementById('feed_builder_channel');
                const saveTargetInput = document.getElementById('feed_builder_target');
                const statusNode = document.getElementById('feed-builder-status');
                const loader = document.getElementById('feed-builder-loader');
                const previewEmpty = document.getElementById('feed-builder-preview-empty');
                const previewResult = document.getElementById('feed-builder-preview-result');
                const previewList = document.getElementById('feed-builder-preview-list');
                const metaStrip = document.getElementById('feed-builder-meta');
                const previewModeButtons = document.querySelectorAll('[data-feed-preview-mode]');
                const saveEmptyState = document.getElementById('feed-builder-save-empty');
                const saveReadyState = document.getElementById('feed-builder-save-ready');
                const destinationTabButtons = document.querySelectorAll('[data-destination-tab]');
                const destinationPanels = document.querySelectorAll('[data-destination-panel]');
                const destinationChoices = document.querySelectorAll('[data-destination-choice]');
                const channelTabButtons = document.querySelectorAll('[data-channel-tab]');
                const channelPanels = document.querySelectorAll('[data-channel-panel]');
                const translateSection = document.getElementById('feed-builder-translate-section');
                const translateToggle = document.getElementById('feed_builder_translate_toggle');
                const translateOptions = document.getElementById('feed-builder-translate-options');
                const translateEnabledInput = document.getElementById('feed_builder_translate_enabled');
                const translateLanguageInput = document.getElementById('feed_builder_translate_language');
                const translateSelect = document.getElementById('feed_builder_translate_select');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const pendingStatuses = new Set(['queued', 'discovering', 'fetching', 'parsing']);
                let pollingTimer = null;
                let generationStream = null;
                let previewMode = 'card';
                let latestPreviewItems = [];

                const statusLabel = (value) => ({
                    queued: 'Queued...',
                    discovering: 'Discovering feed type...',
                    fetching: 'Fetching source...',
                    parsing: 'Parsing feed payload...',
                    ready: 'Preview ready.',
                    failed: 'Generation failed.',
                })[value] || value;

                const setPreviewLoading = (state) => {
                    loader.style.display = state ? 'inline-flex' : 'none';
                    generateButton.disabled = state;
                    generateButton.textContent = state ? 'Generating...' : 'Generate';
                };

                const closeGenerationStream = () => {
                    if (generationStream) {
                        generationStream.close();
                        generationStream = null;
                    }
                };

                const resetPreview = () => {
                    closeGenerationStream();

                    if (pollingTimer) {
                        clearInterval(pollingTimer);
                        pollingTimer = null;
                    }

                    previewList.innerHTML = '';
                    metaStrip.innerHTML = '';
                    previewResult.style.display = 'none';
                    previewEmpty.style.display = 'block';
                    saveSourceInput.value = '';
                    saveTelegramChatInput.value = '';
                    saveChannelInput.value = 'telegram';
                    saveTargetInput.value = '';
                    saveEmptyState.style.display = 'block';
                    saveReadyState.style.display = 'none';
                    latestPreviewItems = [];
                    translateSection.style.display = 'none';
                    translateToggle.checked = false;
                    translateOptions.style.display = 'none';
                    translateEnabledInput.value = '0';
                    translateLanguageInput.value = '';
                    translateSelect.value = '';
                    destinationChoices.forEach((choice) => {
                        choice.disabled = false;
                        choice.classList.remove('is-loading');
                    });
                    // Reset channel tabs to Telegram
                    channelTabButtons.forEach(t => t.classList.toggle('active', t.dataset.channelTab === 'telegram'));
                    channelPanels.forEach(p => p.classList.toggle('active', p.dataset.channelPanel === 'telegram'));
                };

                const sanitizeSummaryText = (value) => {
                    if (typeof value !== 'string' || value.trim() === '') {
                        return '';
                    }

                    const parser = new DOMParser();
                    const doc = parser.parseFromString(`<body>${value}</body>`, 'text/html');

                    return (doc.body?.textContent || '').replace(/\s+/g, ' ').trim();
                };

                const formatPublishedAt = (value) => {
                    const date = new Date(value);

                    if (Number.isNaN(date.getTime())) {
                        return value;
                    }

                    try {
                        return new Intl.DateTimeFormat(undefined, {
                            year: 'numeric',
                            month: 'short',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                            timeZoneName: 'short',
                        }).format(date);
                    } catch (error) {
                        return date.toLocaleString();
                    }
                };

                const renderPreviewItems = (items) => {
                    previewList.className = `feed-builder-preview-list mode-${previewMode}`;
                    previewList.innerHTML = '';

                    items.forEach((item, index) => {
                        const article = document.createElement('article');
                        article.className = 'feed-builder-preview-item';
                        article.style.animationDelay = `${Math.min(index * 45, 280)}ms`;

                        const title = document.createElement('h4');
                        title.textContent = item.title || 'Untitled';

                        const summary = document.createElement('p');
                        summary.className = 'summary';
                        summary.textContent = sanitizeSummaryText(item.summary) || 'No summary available.';

                        const imageUrl = item.image_url || item.image || null;

                        if (imageUrl) {
                            const image = document.createElement('img');
                            image.className = 'feed-builder-preview-cover';
                            image.loading = 'lazy';
                            image.alt = item.title || 'Article image';
                            image.src = imageUrl;
                            image.referrerPolicy = 'no-referrer';
                            article.appendChild(image);
                        }

                        const link = document.createElement('a');
                        link.href = item.url;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        link.textContent = item.url;

                        const published = document.createElement('p');
                        published.className = 'published muted';
                        published.style.fontSize = '12px';
                        published.textContent = item.published_at
                            ? `Published: ${formatPublishedAt(item.published_at)}`
                            : 'Published date unavailable';

                        article.appendChild(title);
                        article.appendChild(summary);
                        article.appendChild(link);
                        article.appendChild(published);
                        previewList.appendChild(article);
                    });
                };

                const renderMeta = (source) => {
                    const warnings = Array.isArray(source.warnings) ? source.warnings : [];
                    const warningText = warnings.length ? warnings.join(' | ') : 'none';
                    const sourceLink = source.source_id
                        ? `<a href="/feeds/${source.source_id}" style="color:#9bc8ff;">Open feed profile</a>`
                        : 'Feed profile will appear after saving.';

                    metaStrip.innerHTML = `
                        <div><strong>Requested:</strong> ${source.requested_url || 'n/a'}</div>
                        <div><strong>Resolved:</strong> ${source.resolved_url || 'n/a'}</div>
                        <div><strong>Type:</strong> ${source.source_type || 'unknown'} | <strong>Confidence:</strong> ${source.confidence ?? 'n/a'}</div>
                        <div><strong>Warnings:</strong> ${warningText}</div>
                        <div>${sourceLink}</div>
                    `;
                };

                const setPreviewMode = (mode) => {
                    previewMode = mode;

                    previewModeButtons.forEach((button) => {
                        button.classList.toggle('active', button.getAttribute('data-feed-preview-mode') === mode);
                    });

                    if (latestPreviewItems.length > 0) {
                        renderPreviewItems(latestPreviewItems);
                    } else {
                        previewList.className = `feed-builder-preview-list mode-${previewMode}`;
                    }
                };

                const setDestinationTab = (tab) => {
                    destinationTabButtons.forEach((button) => {
                        button.classList.toggle('active', button.getAttribute('data-destination-tab') === tab);
                    });

                    destinationPanels.forEach((panel) => {
                        const isActive = panel.getAttribute('data-destination-panel') === tab;
                        panel.hidden = !isActive;
                    });
                };

                const applyGenerationPayload = (payload) => {
                    if (!payload || payload.ok !== true) {
                        return false;
                    }

                    if (payload.status === 'failed' && payload.debug?.timed_out && payload.debug?.admin_url) {
                        statusNode.innerHTML = `${payload.message || statusLabel(payload.status)} <a href="${payload.debug.admin_url}" style="color:#9bc8ff;">Open admin debug</a>`;
                    } else {
                        statusNode.textContent = payload.message || statusLabel(payload.status);
                    }

                    if (pendingStatuses.has(payload.status)) {
                        return false;
                    }

                    setPreviewLoading(false);

                    if (payload.status !== 'ready') {
                        return true;
                    }

                    renderMeta(payload.source || {});
                    latestPreviewItems = Array.isArray(payload.preview) ? payload.preview : [];
                    renderPreviewItems(latestPreviewItems);
                    previewEmpty.style.display = 'none';
                    previewResult.style.display = 'block';
                    saveSourceInput.value = payload.source?.resolved_url || sourceInput.value.trim();
                    saveEmptyState.style.display = 'none';
                    saveReadyState.style.display = 'block';
                    translateSection.style.display = 'block';
                    statusNode.textContent = `${latestPreviewItems.length || 0} preview items ready. Choose where this feed should go.`;

                    return true;
                };

                const pollGeneration = async (generationId) => {
                    try {
                        const response = await fetch(`/feeds/generate/${generationId}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                            },
                        });

                        const payload = await response.json();

                        if (!response.ok || !payload.ok) {
                            statusNode.textContent = payload?.message || 'Failed to poll generation status.';
                            setPreviewLoading(false);

                            if (pollingTimer) {
                                clearInterval(pollingTimer);
                                pollingTimer = null;
                            }

                            return true;
                        }

                        const done = applyGenerationPayload(payload);

                        if (done && pollingTimer) {
                            clearInterval(pollingTimer);
                            pollingTimer = null;
                        }

                        return done;
                    } catch (error) {
                        statusNode.textContent = 'Error while polling generation status.';
                        setPreviewLoading(false);

                        if (pollingTimer) {
                            clearInterval(pollingTimer);
                            pollingTimer = null;
                        }

                        return true;
                    }
                };

                const startPolling = async (generationId) => {
                    const done = await pollGeneration(generationId);

                    if (!done) {
                        pollingTimer = setInterval(() => {
                            pollGeneration(generationId);
                        }, 1000);
                    }
                };

                const streamGeneration = (generationId) => {
                    closeGenerationStream();

                    const eventSource = new EventSource(`/feeds/generate/${generationId}/stream`);
                    generationStream = eventSource;

                    const handleEvent = (event) => {
                        let payload = null;

                        try {
                            payload = JSON.parse(event.data);
                        } catch (error) {
                            return;
                        }

                        const done = applyGenerationPayload(payload);

                        if (done) {
                            closeGenerationStream();
                        }
                    };

                    eventSource.addEventListener('status', handleEvent);
                    eventSource.addEventListener('done', handleEvent);

                    eventSource.onerror = () => {
                        closeGenerationStream();
                        statusNode.textContent = 'Realtime stream interrupted, switching to polling...';
                        startPolling(generationId);
                    };
                };

                previewModeButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const mode = button.getAttribute('data-feed-preview-mode');

                        if (mode) {
                            setPreviewMode(mode);
                        }
                    });
                });

                destinationTabButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const tab = button.getAttribute('data-destination-tab');

                        if (tab) {
                            setDestinationTab(tab);
                        }
                    });
                });

                // Channel tab switching
                channelTabButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const ch = button.dataset.channelTab;
                        channelTabButtons.forEach(t => t.classList.toggle('active', t.dataset.channelTab === ch));
                        channelPanels.forEach(p => {
                            p.classList.toggle('active', p.dataset.channelPanel === ch);
                            if (p.dataset.channelPanel !== ch) p.style.display = 'none';
                            else p.style.display = '';
                        });
                    });
                });

                translateToggle.addEventListener('change', () => {
                    const enabled = translateToggle.checked;
                    translateOptions.style.display = enabled ? 'grid' : 'none';
                    translateEnabledInput.value = enabled ? '1' : '0';

                    if (!enabled) {
                        translateSelect.value = '';
                        translateLanguageInput.value = '';
                    }
                });

                translateSelect.addEventListener('change', () => {
                    translateLanguageInput.value = translateSelect.value;
                });

                destinationChoices.forEach((button) => {
                    button.addEventListener('click', () => {
                        const sourceUrl = saveSourceInput.value.trim();
                        const channel = button.getAttribute('data-destination-channel') || 'telegram';
                        const chatId = button.getAttribute('data-telegram-chat-id') || '';
                        const target = button.getAttribute('data-destination-target') || '';
                        const destinationName = button.getAttribute('data-destination-name') || 'your destination';

                        if (!sourceUrl) {
                            statusNode.textContent = 'Generate preview before choosing a destination.';
                            return;
                        }

                        if (channel === 'telegram' && !chatId) {
                            statusNode.textContent = 'Telegram destination is missing.';
                            return;
                        }

                        if (channel !== 'telegram' && !target) {
                            statusNode.textContent = 'Webhook target is missing.';
                            return;
                        }

                        saveChannelInput.value = channel;
                        saveTelegramChatInput.value = chatId;
                        saveTargetInput.value = target;
                        statusNode.textContent = `Adding this feed to ${destinationName}...`;

                        destinationChoices.forEach((choice) => {
                            choice.disabled = true;
                            choice.classList.remove('is-loading');
                        });

                        button.classList.add('is-loading');
                        saveForm.submit();
                    });
                });

                setPreviewMode(previewMode);
                setDestinationTab('{{ $defaultDestinationTab }}');

                previewForm.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    const sourceUrl = sourceInput.value.trim();

                    if (!sourceUrl) {
                        statusNode.textContent = 'Please enter a URL first.';
                        return;
                    }

                    setPreviewLoading(true);
                    resetPreview();
                    statusNode.textContent = 'Queued...';

                    try {
                        const response = await fetch("{{ route('feeds.generate') }}", {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ source_url: sourceUrl }),
                        });

                        const payload = await response.json();

                        if (!response.ok || !payload.ok) {
                            statusNode.textContent = payload.message || 'Preview generation failed.';
                            setPreviewLoading(false);
                            return;
                        }

                        const generationId = payload.generation_id;
                        statusNode.textContent = payload.message || statusLabel(payload.status);

                        if (!generationId) {
                            statusNode.textContent = 'Generation ID missing in response.';
                            setPreviewLoading(false);
                            return;
                        }

                        if (window.EventSource) {
                            streamGeneration(generationId);
                        } else {
                            startPolling(generationId);
                        }
                    } catch (error) {
                        statusNode.textContent = 'Network error during preview generation. Try again.';
                        setPreviewLoading(false);
                    }
                });
            })();
        </script>
    @endpush
@endonce
