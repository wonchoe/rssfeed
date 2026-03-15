<?php

namespace App\Data\Article;

readonly class NormalizedArticleData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $canonicalUrl,
        public string $canonicalUrlHash,
        public string $contentHash,
        public string $title,
        public ?string $summary = null,
        public ?string $imageUrl = null,
        public ?string $publishedAt = null,
        public array $meta = [],
    ) {}
}
