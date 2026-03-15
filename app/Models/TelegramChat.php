<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TelegramChat extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_user_id',
        'telegram_chat_id',
        'chat_type',
        'title',
        'username',
        'avatar_path',
        'is_forum',
        'bot_membership_status',
        'bot_is_member',
        'added_by_telegram_user_id',
        'discovered_at',
        'linked_at',
        'last_seen_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_forum' => 'boolean',
            'bot_is_member' => 'boolean',
            'discovered_at' => 'datetime',
            'linked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function displayName(): string
    {
        $title = trim((string) $this->title);

        if ($title !== '') {
            return $title;
        }

        $username = trim((string) $this->username);

        if ($username !== '') {
            return '@'.$username;
        }

        return 'Chat '.$this->telegram_chat_id;
    }

    public function kindLabel(): string
    {
        return match ($this->chat_type) {
            'channel' => 'Channel',
            'supergroup' => 'Supergroup',
            'group' => 'Group',
            default => 'Chat',
        };
    }

    public function destinationLabel(): string
    {
        return $this->kindLabel().' · '.$this->displayName();
    }

    public function avatarUrl(): ?string
    {
        $avatarPath = trim((string) $this->avatar_path);

        if ($avatarPath === '') {
            return null;
        }

        return Storage::disk('public')->url($avatarPath);
    }

    public function avatarInitial(): string
    {
        $label = trim((string) ($this->title ?: $this->username ?: 'T'));

        return Str::upper(Str::substr($label, 0, 1));
    }
}
