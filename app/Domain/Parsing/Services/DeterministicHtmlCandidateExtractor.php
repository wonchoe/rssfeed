<?php

namespace App\Domain\Parsing\Services;

use App\Data\Parsing\HtmlCandidateData;
use App\Domain\Parsing\Contracts\HtmlCandidateExtractor;
use App\Support\UrlNormalizer;
use DOMDocument;
use DOMXPath;

class DeterministicHtmlCandidateExtractor implements HtmlCandidateExtractor
{
    /**
     * @return list<HtmlCandidateData>
     */
    public function extract(string $html, string $sourceUrl): array
    {
        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($html);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');

        if ($nodes === false) {
            return [];
        }

        $candidates = [];
        $max = 50;

        foreach ($nodes as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->textContent);
            $title = trim((string) $node->textContent);

            if ($href === '' || $title === '') {
                continue;
            }

            $candidates[] = new HtmlCandidateData(
                selector: 'a[href]',
                url: UrlNormalizer::absolute($href, $sourceUrl),
                title: $title,
                attributes: [],
            );

            if (count($candidates) >= $max) {
                break;
            }
        }

        return $candidates;
    }
}
