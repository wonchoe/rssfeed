<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SourceSchemaResolved
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly bool $valid,
        public readonly array $schema = [],
        public readonly array $context = [],
    ) {}
}
