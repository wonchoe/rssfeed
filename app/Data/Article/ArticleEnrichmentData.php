<?php

namespace App\Data\Article;

readonly class ArticleEnrichmentData
{
    public function __construct(
        public ?string $title = null,
        public ?string $imageUrl = null,
        public ?string $description = null,
    ) {}

    public function hasUsefulData(): bool
    {
        return $this->title !== null || $this->imageUrl !== null || $this->description !== null;
    }
}
