<?php

namespace App\Data\Source;

readonly class SourceCandidateData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $url,
        public string $type,
        public float $confidence,
        public ?string $title = null,
        public ?string $canonicalUrl = null,
        public array $meta = [],
    ) {}
}
