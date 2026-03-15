@php
    $telegramGroupChats = isset($telegramGroupChats)
        ? $telegramGroupChats
        : $telegramChats->filter(fn ($chat) => in_array($chat->chat_type, ['group', 'supergroup'], true))->values();
    $telegramChannelChats = isset($telegramChannelChats)
        ? $telegramChannelChats
        : $telegramChats->filter(fn ($chat) => $chat->chat_type === 'channel')->values();
@endphp

@once
    @push('styles')
        <style>
            .telegram-bridge {
                display: grid;
                gap: 14px;
            }

            .telegram-bridge__header {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
                align-items: flex-start;
            }

            .telegram-bridge__header h4 {
                margin: 0 0 6px;
                font-size: 18px;
                font-family: "Manrope", sans-serif;
            }

            .telegram-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .telegram-actions form {
                margin: 0;
            }

            .telegram-steps {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
            }

            .telegram-step {
                border: 1px solid var(--line);
                border-radius: 12px;
                padding: 10px 12px;
                background: rgba(10, 20, 38, 0.7);
                color: var(--muted);
                font-size: 12px;
                line-height: 1.45;
            }

            .telegram-step.is-ready {
                border-color: rgba(34, 197, 94, 0.4);
                background: rgba(34, 197, 94, 0.1);
                color: #aef1c4;
            }

            .telegram-actions-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .telegram-action-card {
                border: 1px solid var(--line);
                border-radius: 14px;
                background: rgba(10, 20, 38, 0.7);
                padding: 14px;
                display: grid;
                gap: 10px;
            }

            .telegram-action-card h5,
            .telegram-action-card p {
                margin: 0;
            }

            .telegram-list-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .telegram-list-card {
                border: 1px solid var(--line);
                border-radius: 14px;
                background: rgba(10, 20, 38, 0.56);
                padding: 14px;
                display: grid;
                gap: 10px;
            }

            .telegram-list-card h5,
            .telegram-list-card p {
                margin: 0;
            }

            .telegram-chat-list {
                display: grid;
                gap: 8px;
            }

            .telegram-chat-item {
                border: 1px solid var(--line);
                border-radius: 12px;
                background: rgba(10, 20, 38, 0.7);
                padding: 10px 12px;
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                gap: 10px;
                align-items: center;
            }

            .telegram-chat-avatar {
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

            .telegram-chat-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }

            .telegram-chat-item strong {
                display: block;
                margin-bottom: 4px;
            }

            .telegram-chat-copy {
                min-width: 0;
            }

            .telegram-chat-meta {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                font-size: 12px;
            }

            .telegram-chat-actions {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
                justify-content: flex-end;
            }

            .telegram-chat-actions form {
                margin: 0;
            }

            .telegram-chat-chip {
                display: inline-flex;
                align-items: center;
                border-radius: 999px;
                border: 1px solid var(--line);
                padding: 4px 9px;
                background: rgba(23, 39, 68, 0.66);
                color: var(--muted);
                font-size: 11px;
                font-weight: 700;
            }

            .telegram-chat-remove {
                padding: 8px 11px;
                font-size: 12px;
            }

            .telegram-note {
                border: 1px dashed var(--line);
                border-radius: 12px;
                padding: 12px;
                color: var(--muted);
                background: rgba(10, 20, 38, 0.45);
                font-size: 13px;
                line-height: 1.5;
            }

            .telegram-request-status {
                display: none;
                align-items: center;
                gap: 10px;
                border: 1px solid rgba(93, 168, 255, 0.35);
                border-radius: 12px;
                padding: 12px;
                color: #c9ddff;
                background: rgba(93, 168, 255, 0.08);
                font-size: 13px;
                line-height: 1.4;
            }

            .telegram-request-status.is-active {
                display: flex;
            }

            .telegram-submit-button.is-loading {
                opacity: .78;
                pointer-events: none;
            }

            .telegram-submit-spinner,
            .telegram-request-spinner {
                width: 14px;
                height: 14px;
                border-radius: 999px;
                border: 2px solid currentColor;
                border-right-color: transparent;
                animation: telegram-spin .75s linear infinite;
                display: none;
                flex: 0 0 auto;
            }

            .telegram-submit-button.is-loading .telegram-submit-spinner {
                display: inline-block;
            }

            .telegram-request-status.is-active .telegram-request-spinner {
                display: inline-block;
            }

            @keyframes telegram-spin {
                to {
                    transform: rotate(360deg);
                }
            }

            @media (max-width: 940px) {
                .telegram-steps,
                .telegram-actions-grid,
                .telegram-list-grid {
                    grid-template-columns: 1fr;
                }

                .telegram-chat-item {
                    grid-template-columns: auto minmax(0, 1fr);
                }

                .telegram-chat-actions {
                    grid-column: 1 / -1;
                    justify-content: flex-start;
                    padding-left: 52px;
                }
            }
        </style>
    @endpush
@endonce

<section class="telegram-bridge">
    <div class="telegram-bridge__header">
        <div>
            <h4>Telegram Destinations</h4>
            <p class="muted" style="margin:0;">Link your Telegram account once, then add the groups and channels where the bot is allowed to deliver updates.</p>
        </div>

        <div class="telegram-actions">
            @if (! $telegramUserLink && $telegramBotUsername !== '')
                <form
                    method="POST"
                    action="{{ route('telegram.connect') }}"
                    data-telegram-submit
                    data-telegram-submit-message="Opening Telegram so you can link your account..."
                    data-telegram-connect
                >
                    @csrf
                    <button type="submit" class="btn telegram-submit-button">
                        <span class="telegram-submit-spinner" aria-hidden="true"></span>
                        <span data-submit-label>Connect Telegram</span>
                    </button>
                </form>
            @endif

            @if ($telegramBotUsername !== '')
                <a
                    href="https://t.me/{{ ltrim($telegramBotUsername, '@') }}"
                    target="_blank"
                    rel="noreferrer"
                    class="btn-secondary"
                >
                    Open Bot
                </a>
            @endif
        </div>
    </div>

    <div class="telegram-steps">
        <div class="telegram-step {{ $telegramUserLink ? 'is-ready' : '' }}">
            1. Connect your Telegram account so rss.cursor.style can map your web user to your Telegram user ID.
        </div>
        <div class="telegram-step {{ $telegramUserLink ? 'is-ready' : '' }}">
            2. Add the bot to a group or make it an admin in a channel.
        </div>
        <div class="telegram-step {{ $telegramChats->isNotEmpty() ? 'is-ready' : '' }}">
            3. Add the destination so feeds can target it directly.
        </div>
    </div>

    <div class="telegram-note">
        Telegram bots do not get a complete global list of all groups/channels they belong to. We infer candidates from membership updates and use Add to make the user-to-destination link explicit. For channels, the bot should have posting rights.
    </div>

    <div class="telegram-note">
        New groups and channels appear here automatically within a few seconds while this page stays open.
    </div>

    <div class="telegram-request-status" data-telegram-request-status aria-live="polite">
        <span class="telegram-request-spinner" aria-hidden="true"></span>
        <span data-telegram-request-status-text>Working on your Telegram request...</span>
    </div>

    @if ($telegramUserLink)
        <div class="telegram-note">
            Connected as <strong style="color:var(--text);">{{ $telegramUserLink->displayHandle() }}</strong>
            <span style="display:block; margin-top:4px;">Telegram user ID: {{ $telegramUserLink->telegram_user_id }}</span>
        </div>

        <div class="telegram-actions-grid">
            <div class="telegram-action-card">
                <div>
                    <h5>Groups</h5>
                    <p class="muted">Best for team discussion threads and shared delivery streams.</p>
                </div>
                <form
                    method="POST"
                    action="{{ route('telegram.verify') }}"
                    data-telegram-submit
                    data-telegram-submit-message="Sending a Telegram request so you can choose a group..."
                >
                    @csrf
                    <input type="hidden" name="chat_kind" value="group">
                    <button type="submit" class="btn-secondary telegram-submit-button">
                        <span class="telegram-submit-spinner" aria-hidden="true"></span>
                        <span data-submit-label>Add Group</span>
                    </button>
                </form>
            </div>

            <div class="telegram-action-card">
                <div>
                    <h5>Channels</h5>
                    <p class="muted">Best for one-way broadcasts. The bot should be a channel admin that can post.</p>
                </div>
                <form
                    method="POST"
                    action="{{ route('telegram.verify') }}"
                    data-telegram-submit
                    data-telegram-submit-message="Sending a Telegram request so you can choose a channel..."
                >
                    @csrf
                    <input type="hidden" name="chat_kind" value="channel">
                    <button type="submit" class="btn-secondary telegram-submit-button">
                        <span class="telegram-submit-spinner" aria-hidden="true"></span>
                        <span data-submit-label>Add Channel</span>
                    </button>
                </form>
            </div>
        </div>

        <div class="telegram-list-grid">
            <div class="telegram-list-card">
                <div>
                    <h5>Linked Groups</h5>
                    <p class="muted">Groups and supergroups verified for this account.</p>
                </div>

                @if ($telegramGroupChats->isEmpty())
                    <div class="telegram-note">
                        No Telegram groups linked yet. Add the bot to a group, then click Add Group.
                    </div>
                @else
                    <div class="telegram-chat-list">
                        @foreach ($telegramGroupChats as $telegramChat)
                            <div class="telegram-chat-item">
                                <div class="telegram-chat-avatar">
                                    @if ($telegramChat->avatarUrl())
                                        <img src="{{ $telegramChat->avatarUrl() }}" alt="{{ $telegramChat->displayName() }}">
                                    @else
                                        {{ $telegramChat->avatarInitial() }}
                                    @endif
                                </div>
                                <div class="telegram-chat-copy">
                                    <strong>{{ $telegramChat->displayName() }}</strong>
                                    <div class="telegram-chat-meta muted">
                                        <span>{{ $telegramChat->kindLabel() }} · chat ID {{ $telegramChat->telegram_chat_id }}</span>
                                        <span>{{ (int) ($telegramChat->subscriptions_count ?? 0) === 1 ? 'Used by 1 feed' : 'Used by '.number_format((int) ($telegramChat->subscriptions_count ?? 0)).' feeds' }}</span>
                                    </div>
                                </div>
                                <div class="telegram-chat-actions">
                                    @if ((int) ($telegramChat->subscriptions_count ?? 0) > 0)
                                        <span class="telegram-chat-chip">In use</span>
                                    @endif

                                    <form
                                        method="POST"
                                        action="{{ route('telegram.chats.destroy', $telegramChat) }}"
                                        data-telegram-submit
                                        data-telegram-submit-message="Removing this Telegram group from your workspace..."
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="btn-danger telegram-chat-remove telegram-submit-button"
                                            onclick="return confirm('Remove this Telegram group from your workspace and ask the bot to leave it?');"
                                        >
                                            <span class="telegram-submit-spinner" aria-hidden="true"></span>
                                            <span data-submit-label>Remove</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="telegram-list-card">
                <div>
                    <h5>Linked Channels</h5>
                    <p class="muted">Broadcast channels verified for this account.</p>
                </div>

                @if ($telegramChannelChats->isEmpty())
                    <div class="telegram-note">
                        No Telegram channels linked yet. Add the bot as a channel admin, then click Add Channel.
                    </div>
                @else
                    <div class="telegram-chat-list">
                        @foreach ($telegramChannelChats as $telegramChat)
                            <div class="telegram-chat-item">
                                <div class="telegram-chat-avatar">
                                    @if ($telegramChat->avatarUrl())
                                        <img src="{{ $telegramChat->avatarUrl() }}" alt="{{ $telegramChat->displayName() }}">
                                    @else
                                        {{ $telegramChat->avatarInitial() }}
                                    @endif
                                </div>
                                <div class="telegram-chat-copy">
                                    <strong>{{ $telegramChat->displayName() }}</strong>
                                    <div class="telegram-chat-meta muted">
                                        <span>{{ $telegramChat->kindLabel() }} · chat ID {{ $telegramChat->telegram_chat_id }}</span>
                                        <span>{{ (int) ($telegramChat->subscriptions_count ?? 0) === 1 ? 'Used by 1 feed' : 'Used by '.number_format((int) ($telegramChat->subscriptions_count ?? 0)).' feeds' }}</span>
                                    </div>
                                </div>
                                <div class="telegram-chat-actions">
                                    @if ((int) ($telegramChat->subscriptions_count ?? 0) > 0)
                                        <span class="telegram-chat-chip">In use</span>
                                    @endif

                                    <form
                                        method="POST"
                                        action="{{ route('telegram.chats.destroy', $telegramChat) }}"
                                        data-telegram-submit
                                        data-telegram-submit-message="Removing this Telegram channel from your workspace..."
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            class="btn-danger telegram-chat-remove telegram-submit-button"
                                            onclick="return confirm('Remove this Telegram channel from your workspace and ask the bot to leave it?');"
                                        >
                                            <span class="telegram-submit-spinner" aria-hidden="true"></span>
                                            <span data-submit-label>Remove</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="telegram-note">
            Telegram is not linked yet. We need that one-time account link before we can safely attach any group or channel to your workspace user.
        </div>
    @endif

    @if ($telegramBotUsername === '')
        <div class="telegram-note">
            Configure <code>TELEGRAM_BOT_USERNAME</code> to enable one-click connect links from the workspace.
        </div>
    @endif
</section>
