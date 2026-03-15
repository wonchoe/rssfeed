<?php

namespace App\Domain\Parsing\Contracts;

use App\Data\Parsing\ParsedArticleData;

interface FeedParserService
{
    /**
     * @return array<int, ParsedArticleData>
     */
    public function parse(string $payload, string $sourceUrl, string $sourceType): array;
}
