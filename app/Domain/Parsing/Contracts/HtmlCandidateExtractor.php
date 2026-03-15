<?php

namespace App\Domain\Parsing\Contracts;

use App\Data\Parsing\HtmlCandidateData;

interface HtmlCandidateExtractor
{
    /**
     * @return array<int, HtmlCandidateData>
     */
    public function extract(string $html, string $sourceUrl): array;
}
