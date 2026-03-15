<?php

namespace App\Domain\Article\Contracts;

use App\Data\Article\NormalizedArticleData;
use App\Data\Parsing\ParsedArticleData;

interface ArticleNormalizer
{
    public function normalize(ParsedArticleData $article): NormalizedArticleData;
}
