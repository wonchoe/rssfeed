<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceFetch extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'fetched_url_hash',
        'fetched_url',
        'http_status',
        'etag',
        'last_modified',
        'content_hash',
        'payload_bytes',
        'fetched_at',
        'request_context',
        'response_headers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'request_context' => 'array',
            'response_headers' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
