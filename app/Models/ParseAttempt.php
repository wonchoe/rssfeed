<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ParseAttempt extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'parser_schema_id',
        'stage',
        'status',
        'http_status',
        'error_type',
        'error_message',
        'response_time_ms',
        'retry_count',
        'used_browser',
        'used_ai',
        'snapshot_reference',
        'context',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'used_browser' => 'boolean',
            'used_ai' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function parserSchema(): BelongsTo
    {
        return $this->belongsTo(ParserSchema::class);
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(SourceSnapshot::class);
    }
}
