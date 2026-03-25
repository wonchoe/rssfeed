@php
    $webhooks = $webhooks ?? collect();
@endphp

@if ($webhooks->isNotEmpty())
    <div class="webhook-list" style="margin-bottom:14px;">
        @foreach ($webhooks as $webhook)
            <div class="webhook-item">
                <div class="webhook-icon {{ $channel }}">{{ $icon }}</div>
                <div class="webhook-copy">
                    <strong>{{ $webhook['label'] ?: ($channel === 'email' ? $webhook['target'] : 'Webhook #'.$loop->iteration) }}</strong>
                    @if ($channel !== 'email')
                        <span class="webhook-url-preview">URL hidden for security.</span>
                    @endif
                    @if ($webhook['feed_count'] > 0)
                        <span class="webhook-url-preview">Used by {{ $webhook['feed_count'] }} {{ Str::plural('feed', $webhook['feed_count']) }}</span>
                    @endif
                </div>
                <div class="webhook-actions">
                    @if ($webhook['feed_count'] > 0)
                        <span class="badge badge-ok" style="font-size:11px;">In use</span>
                    @endif
                    <button
                        type="button"
                        class="btn-danger"
                        style="padding:8px 12px; font-size:12px;"
                        data-webhook-delete="{{ $webhook['id'] }}"
                    >Remove</button>
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="intg-empty" style="margin-bottom:14px;">
        @if ($channel === 'email')
            No email addresses added yet. Add one below to start receiving daily digests.
        @else
            No {{ ucfirst($channel) }} webhooks added yet. Add your first webhook below.
        @endif
    </div>
@endif
