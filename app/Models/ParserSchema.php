<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParserSchema extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'version',
        'strategy_type',
        'schema_payload',
        'confidence_score',
        'validation_score',
        'created_by',
        'is_active',
        'is_shadow',
        'last_validated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_payload' => 'array',
            'confidence_score' => 'decimal:2',
            'validation_score' => 'decimal:2',
            'is_active' => 'boolean',
            'is_shadow' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function parseAttempts(): HasMany
    {
        return $this->hasMany(ParseAttempt::class);
    }
}
