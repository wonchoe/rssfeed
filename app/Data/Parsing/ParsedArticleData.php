<?php

namespace App\Data\Parsing;

readonly class ParsedArticleData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $url,
        public string $title,
        public ?string $externalId = null,
        public ?string $summary = null,
        public ?string $imageUrl = null,
        public ?string $publishedAt = null,
        public array $meta = [],
    ) {}
}
