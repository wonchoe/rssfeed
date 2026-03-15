<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_url_hash',
        'source_url',
        'canonical_url_hash',
        'canonical_url',
        'domain',
        'host',
        'source_type',
        'status',
        'usage_state',
        'health_score',
        'health_state',
        'consecutive_failures',
        'polling_interval_minutes',
        'last_fetched_at',
        'last_parsed_at',
        'last_success_at',
        'last_attempt_at',
        'next_check_at',
        'latest_content_hash',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_fetched_at' => 'datetime',
            'last_parsed_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'next_check_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function fetches(): HasMany
    {
        return $this->hasMany(SourceFetch::class);
    }

    public function latestFetch(): HasOne
    {
        return $this->hasOne(SourceFetch::class)->latestOfMany('fetched_at');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(SourceAlias::class);
    }

    public function feedGenerations(): HasMany
    {
        return $this->hasMany(FeedGeneration::class);
    }

    public function parserSchemas(): HasMany
    {
        return $this->hasMany(ParserSchema::class);
    }

    public function activeParserSchema(): HasOne
    {
        return $this->hasOne(ParserSchema::class)->where('is_active', true)->latestOfMany('id');
    }

    public function parseAttempts(): HasMany
    {
        return $this->hasMany(ParseAttempt::class);
    }

    public function latestParseAttempt(): HasOne
    {
        return $this->hasOne(ParseAttempt::class)->latestOfMany('started_at');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(SourceSnapshot::class)->latestOfMany('captured_at');
    }
}
