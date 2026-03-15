<?php

namespace App\Domain\Article\Contracts;

use App\Data\Article\NormalizedArticleData;

interface DeduplicationService
{
    public function fingerprint(NormalizedArticleData $article): string;

    public function isDuplicate(NormalizedArticleData $article): bool;
}
