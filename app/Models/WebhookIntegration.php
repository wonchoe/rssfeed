<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookIntegration extends Model
{
    protected $fillable = [
        'user_id',
        'channel',
        'webhook_url',
        'url_hash',
        'label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionCount(): int
    {
        return Subscription::query()
            ->where('user_id', $this->user_id)
            ->where('channel', $this->channel)
            ->where('target_hash', $this->url_hash)
            ->where('is_active', true)
            ->count();
    }
}
