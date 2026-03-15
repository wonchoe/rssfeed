<?php

namespace App\Domain\Article\Services;

use App\Data\Article\NormalizedArticleData;
use App\Domain\Article\Contracts\DeduplicationService;
use App\Models\Article;

class DatabaseDeduplicationService implements DeduplicationService
{
    public function fingerprint(NormalizedArticleData $article): string
    {
        return $article->canonicalUrlHash;
    }

    public function isDuplicate(NormalizedArticleData $article): bool
    {
        return Article::query()
            ->where('canonical_url_hash', $article->canonicalUrlHash)
            ->orWhere('content_hash', $article->contentHash)
            ->exists();
    }
}
