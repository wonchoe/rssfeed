<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramUserLink extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'connected_at',
        'last_seen_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatRequests(): HasMany
    {
        return $this->hasMany(TelegramChatRequest::class);
    }

    public function displayHandle(): string
    {
        $username = trim((string) $this->username);

        if ($username !== '') {
            return '@'.$username;
        }

        $name = trim(implode(' ', array_filter([
            $this->first_name,
            $this->last_name,
        ])));

        return $name !== '' ? $name : $this->telegram_user_id;
    }
}
