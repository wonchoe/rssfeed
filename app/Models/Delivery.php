<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'subscription_id',
        'channel',
        'status',
        'attempts',
        'provider_message_id',
        'error_message',
        'queued_at',
        'delivered_at',
        'failed_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
