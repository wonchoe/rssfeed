@php
    $telegramGroupChats = isset($telegramGroupChats)
        ? $telegramGroupChats
        : $telegramChats->filter(fn ($chat) => in_array($chat->chat_type, ['group', 'supergroup'], true))->values();
    $telegramChannelChats = isset($telegramChannelChats)
        ? $telegramChannelChats
        : $telegramChats->filter(fn ($chat) => $chat->chat_type === 'channel')->values();
    $destCount = $telegramGroupChats->count() + $telegramChannelChats->count();
@endphp

@once
    @push('styles')
        <style>
            .tg-account-row {
                display: flex;
                align-items: center;
                gap: 12px;
                min-height: 44px;
            }

            .tg-status-dot {
                width: 9px;
                height: 9px;
                border-radius: 50%;
                flex: 0 0 auto;
            }

            .tg-status-dot--on {
                background: #22c55e;
                box-shadow: 0 0 6px rgba(34, 197, 94, .55);
            }

            .tg-status-dot--off { background: var(--line); }

            .tg-acct-body { flex: 1; min-width: 0; }

            .tg-acct-name {
                font-size: 14px;
                font-weight: 600;
                margin: 0 0 2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .tg-acct-sub { font-size: 12px; color: var(--muted); margin: 0; }

            .tg-acct-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }

            .tg-sm-btn {
                height: 32px;
                font-size: 12px;
                padding: 0 14px;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                border-radius: 9px;
                cursor: pointer;
            }

            .tg-divider {
                border: none;
                border-top: 1px solid var(--line);
                margin: 18px 0;
            }

            .tg-section-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 10px;
            }

            .tg-section-label {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .6px;
                color: var(--muted);
            }

            .tg-list { display: grid; gap: 2px; }

            .tg-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 6px;
                border-radius: 10px;
                transition: background .15s;
            }

            .tg-item:hover { background: rgba(255,255,255,.03); }

            .tg-avatar {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background: linear-gradient(135deg, #5da8ff 0%, #ef8f4e 100%);
                color: #06142a;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 800;
                font-size: 12px;
                font-family: "Manrope", sans-serif;
                flex: 0 0 auto;
                overflow: hidden;
            }

            .tg-avatar img { width: 100%; height: 100%; object-fit: cover; }

            .tg-item-body { flex: 1; min-width: 0; }

            .tg-item-name {
                font-size: 13px;
                font-weight: 600;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .tg-item-sub { font-size: 11px; color: var(--muted); margin-top: 1px; }

            .tg-item-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
            .tg-item-actions form { margin: 0; }

            .tg-empty {
                font-size: 13px;
                color: var(--muted);
                padding: 4px 6px;
            }

            .tg-request-status {
                display: none;
                align-items: center;
                gap: 8px;
                padding: 8px 10px;
                border-radius: 10px;
                border: 1px solid rgba(93,168,255,.25);
                background: rgba(93,168,255,.07);
                color: #c9ddff;
                font-size: 12px;
                margin-top: 12px;
            }

            .tg-request-status.is-active { display: flex; }

            .tg-spinner {
                width: 12px;
                height: 12px;
                border-radius: 50%;
                border: 2px solid currentColor;
                border-right-color: transparent;
                animation: tg-spin2 .72s linear infinite;
                display: none;
                flex: 0 0 auto;
            }

            .is-loading .tg-spinner { display: inline-block; }
            .is-loading { opacity: .72; pointer-events: none; }

            @keyframes tg-spin2 { to { transform: rotate(360deg); } }
        </style>
    @endpush
@endonce

<div class="tg-account-row">
    @if ($telegramUserLink)
        <span class="tg-status-dot tg-status-dot--on" title="Connected"></span>
        <div class="tg-acct-body">
            <p class="tg-acct-name">{{ $telegramUserLink->displayHandle() }}</p>
            <p class="tg-acct-sub">
                ID {{ $telegramUserLink->telegram_user_id }}
                @if ($destCount > 0)
                    &middot; {{ $destCount }} {{ Str::plural('destination', $destCount) }}
                @endif
            </p>
        </div>
        <div class="tg-acct-actions">
            <form
                method="POST"
                action="{{ route('telegram.disconnect') }}"
                onsubmit="return confirm('Disconnect Telegram account?');"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-danger tg-sm-btn">Disconnect</button>
            </form>
        </div>
    @else
        <span class="tg-status-dot tg-status-dot--off"></span>
        <div class="tg-acct-body">
            <p class="tg-acct-name" style="color:var(--muted); font-weight:400;">Not connected</p>
            @if ($telegramBotUsername === '')
                <p class="tg-acct-sub">Configure TELEGRAM_BOT_USERNAME to enable.</p>
            @endif
        </div>
        @if ($telegramBotUsername !== '')
            <div class="tg-acct-actions">
                <button type="button" class="btn tg-sm-btn" data-tg-connect-trigger>Connect</button>
            </div>
        @endif
    @endif
</div>

@if ($telegramUserLink)

<hr class="tg-divider">

{{-- Groups --}}
<div>
    <div class="tg-section-header">
        <span class="tg-section-label">Groups ({{ $telegramGroupChats->count() }})</span>
        <form method="POST" action="{{ route('telegram.verify') }}" data-telegram-submit data-telegram-submit-message="Sending group request to Telegram...">
            @csrf
            <input type="hidden" name="chat_kind" value="group">
            <button type="submit" class="btn-secondary tg-sm-btn">
                <span class="tg-spinner"></span>+ Add
            </button>
        </form>
    </div>
    <div class="tg-list">
        @forelse ($telegramGroupChats as $chat)
            <div class="tg-item">
                <div class="tg-avatar">
                    @if ($chat->avatarUrl())
                        <img src="{{ $chat->avatarUrl() }}" alt="">
                    @else
                        {{ $chat->avatarInitial() }}
                    @endif
                </div>
                <div class="tg-item-body">
                    <div class="tg-item-name">{{ $chat->displayName() }}</div>
                    <div class="tg-item-sub">
                        {{ $chat->kindLabel() }}@if ((int)($chat->subscriptions_count ?? 0) > 0) &middot; {{ (int)$chat->subscriptions_count }} {{ Str::plural('feed', (int)$chat->subscriptions_count) }}@endif
                    </div>
                </div>
                <div class="tg-item-actions">
                    <form method="POST" action="{{ route('telegram.chats.destroy', $chat) }}" data-telegram-submit data-telegram-submit-message="Removing group..." onsubmit="return confirm('Remove this group?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn-danger tg-sm-btn">
                            <span class="tg-spinner"></span>Remove
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <p class="tg-empty">No groups yet. Add the bot to a group, then click + Add.</p>
        @endforelse
    </div>
</div>

<hr class="tg-divider">

{{-- Channels --}}
<div>
    <div class="tg-section-header">
        <span class="tg-section-label">Channels ({{ $telegramChannelChats->count() }})</span>
        <form method="POST" action="{{ route('telegram.verify') }}" data-telegram-submit data-telegram-submit-message="Sending channel request to Telegram...">
            @csrf
            <input type="hidden" name="chat_kind" value="channel">
            <button type="submit" class="btn-secondary tg-sm-btn">
                <span class="tg-spinner"></span>+ Add
            </button>
        </form>
    </div>
    <div class="tg-list">
        @forelse ($telegramChannelChats as $chat)
            <div class="tg-item">
                <div class="tg-avatar">
                    @if ($chat->avatarUrl())
                        <img src="{{ $chat->avatarUrl() }}" alt="">
                    @else
                        {{ $chat->avatarInitial() }}
                    @endif
                </div>
                <div class="tg-item-body">
                    <div class="tg-item-name">{{ $chat->displayName() }}</div>
                    <div class="tg-item-sub">
                        {{ $chat->kindLabel() }}@if ((int)($chat->subscriptions_count ?? 0) > 0) &middot; {{ (int)$chat->subscriptions_count }} {{ Str::plural('feed', (int)$chat->subscriptions_count) }}@endif
                    </div>
                </div>
                <div class="tg-item-actions">
                    <form method="POST" action="{{ route('telegram.chats.destroy', $chat) }}" data-telegram-submit data-telegram-submit-message="Removing channel..." onsubmit="return confirm('Remove this channel?');">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn-danger tg-sm-btn">
                            <span class="tg-spinner"></span>Remove
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <p class="tg-empty">No channels yet. Make the bot a channel admin, then click + Add.</p>
        @endforelse
    </div>
</div>

{{-- Request status bar (shown while waiting for a verify response) --}}
<div class="tg-request-status" data-telegram-request-status aria-live="polite">
    <span style="width:12px;height:12px;border-radius:50%;border:2px solid currentColor;border-right-color:transparent;animation:tg-spin2 .72s linear infinite;flex:0 0 auto;display:inline-block;"></span>
    <span data-telegram-request-status-text>Working…</span>
</div>

@endif
