<?php

namespace App\Domain\Parsing\Services;

use App\Data\Parsing\ParsedArticleData;
use App\Domain\Parsing\Contracts\FeedParserService;
use App\Models\ParserSchema;
use App\Models\Source;
use App\Support\UrlNormalizer;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DeterministicFeedParserService implements FeedParserService
{
    /**
     * @return list<ParsedArticleData>
     */
    public function parse(string $payload, string $sourceUrl, string $sourceType): array
    {
        $attemptOrder = $this->attemptOrder($sourceType);

        foreach ($attemptOrder as $type) {
            $parsed = match ($type) {
                'rss' => $this->parseRss($payload, $sourceUrl),
                'atom' => $this->parseAtom($payload, $sourceUrl),
                'json_feed' => $this->parseJsonFeed($payload, $sourceUrl),
                default => [],
            };

            if ($parsed !== []) {
                return array_slice($parsed, 0, (int) config('ingestion.parse_max_items', 100));
            }
        }

        $schemaParsed = $this->parseWithActiveSchema($payload, $sourceUrl);

        if ($schemaParsed !== []) {
            return array_slice($schemaParsed, 0, (int) config('ingestion.parse_max_items', 100));
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function attemptOrder(string $sourceType): array
    {
        $ordered = match ($sourceType) {
            'rss' => ['rss', 'atom', 'json_feed'],
            'atom' => ['atom', 'rss', 'json_feed'],
            'json_feed' => ['json_feed', 'rss', 'atom'],
            default => ['rss', 'atom', 'json_feed'],
        };

        return array_values(array_unique($ordered));
    }

    /**
     * @return list<ParsedArticleData>
     */
    private function parseRss(string $payload, string $sourceUrl): array
    {
        $xml = $this->loadXml($payload);

        if ($xml === null) {
            return [];
        }

        $root = Str::lower($xml->getName());

        if (! in_array($root, ['rss', 'rdf'], true)) {
            return [];
        }

        $items = [];

        if (isset($xml->channel->item)) {
            $items = $xml->channel->item;
        } elseif (isset($xml->item)) {
            $items = $xml->item;
        }

        $results = [];

        foreach ($items as $item) {
            $url = trim((string) ($item->link ?? ''));
            $title = $this->cleanText((string) ($item->title ?? ''));

            if ($url === '' || $title === '') {
                continue;
            }

            $description = (string) ($item->description ?? '');
            $contentEncoded = $this->extractContentEncoded($item);
            $summary = $this->cleanSummary($description !== '' ? $description : $contentEncoded);
            $imageUrl = $this->extractRssImage($item, $sourceUrl, $contentEncoded !== '' ? $contentEncoded : $description);
            $guid = trim((string) ($item->guid ?? ''));
            $published = $this->toIso8601((string) ($item->pubDate ?? ''));

            $results[] = new ParsedArticleData(
                url: UrlNormalizer::absolute($url, $sourceUrl),
                title: $title,
                externalId: $guid !== '' ? $guid : null,
                summary: $summary !== '' ? $summary : null,
                imageUrl: $imageUrl,
                publishedAt: $published,
                meta: [
                    'source_type' => 'rss',
                ],
            );
        }

        return $this->deduplicateByUrl($results);
    }

    /**
     * @return list<ParsedArticleData>
     */
    private function parseAtom(string $payload, string $sourceUrl): array
    {
        $xml = $this->loadXml($payload);

        if ($xml === null || Str::lower($xml->getName()) !== 'feed') {
            return [];
        }

        $defaultNamespace = $xml->getNamespaces(true)[''] ?? 'http://www.w3.org/2005/Atom';
        $feed = $xml->children($defaultNamespace);

        if (! isset($feed->entry)) {
            return [];
        }

        $results = [];

        foreach ($feed->entry as $entry) {
            $entryNode = $entry->children($defaultNamespace);
            $title = $this->cleanText((string) ($entryNode->title ?? ''));
            $id = trim((string) ($entryNode->id ?? ''));

            if ($title === '') {
                continue;
            }

            $url = '';

            foreach ($entryNode->link as $link) {
                $attributes = $link->attributes();
                $href = trim((string) ($attributes['href'] ?? ''));
                $rel = Str::lower((string) ($attributes['rel'] ?? 'alternate'));

                if ($href !== '' && in_array($rel, ['', 'alternate'], true)) {
                    $url = $href;

                    break;
                }

                if ($url === '' && $href !== '') {
                    $url = $href;
                }
            }

            if ($url === '') {
                continue;
            }

            $summary = $this->cleanSummary((string) ($entryNode->summary ?? $entryNode->content ?? ''));
            $imageUrl = $this->extractAtomImage($entryNode, $sourceUrl, (string) ($entryNode->content ?? $entryNode->summary ?? ''));
            $published = $this->toIso8601((string) ($entryNode->published ?? $entryNode->updated ?? ''));

            $results[] = new ParsedArticleData(
                url: UrlNormalizer::absolute($url, $sourceUrl),
                title: $title,
                externalId: $id !== '' ? $id : null,
                summary: $summary !== '' ? $summary : null,
                imageUrl: $imageUrl,
                publishedAt: $published,
                meta: [
                    'source_type' => 'atom',
                ],
            );
        }

        return $this->deduplicateByUrl($results);
    }

    /**
     * @return list<ParsedArticleData>
     */
    private function parseJsonFeed(string $payload, string $sourceUrl): array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (! $this->isJsonFeed($decoded)) {
            return [];
        }

        $items = $decoded['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $urlValue = $item['url'] ?? $item['external_url'] ?? '';

            if (! is_string($urlValue) || trim($urlValue) === '') {
                continue;
            }

            $titleValue = $item['title'] ?? '';

            if (! is_string($titleValue) || trim($titleValue) === '') {
                $titleValue = Str::limit(strip_tags((string) ($item['content_text'] ?? $item['content_html'] ?? 'Untitled item')), 120, '');
            }

            if (trim($titleValue) === '') {
                continue;
            }

            $summaryValue = $item['summary'] ?? $item['content_text'] ?? $item['content_html'] ?? null;
            $summary = is_string($summaryValue) && trim($summaryValue) !== ''
                ? $this->cleanSummary($summaryValue)
                : null;
            $imageUrl = $this->extractJsonFeedImage($item, $sourceUrl);

            $externalIdValue = $item['id'] ?? null;
            $externalId = is_string($externalIdValue) && trim($externalIdValue) !== ''
                ? trim($externalIdValue)
                : null;

            $publishedValue = $item['date_published'] ?? $item['date_modified'] ?? null;
            $published = is_string($publishedValue)
                ? $this->toIso8601($publishedValue)
                : null;

            $results[] = new ParsedArticleData(
                url: UrlNormalizer::absolute((string) $urlValue, $sourceUrl),
                title: trim((string) $titleValue),
                externalId: $externalId,
                summary: $summary,
                imageUrl: $imageUrl,
                publishedAt: $published,
                meta: [
                    'source_type' => 'json_feed',
                ],
            );
        }

        return $this->deduplicateByUrl($results);
    }

    private function loadXml(string $payload): ?\SimpleXMLElement
    {
        $body = trim($payload);

        if ($body === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();

        return $xml === false ? null : $xml;
    }

    private function toIso8601(string $value): ?string
    {
        $candidate = trim($value);

        if ($candidate === '') {
            return null;
        }

        try {
            return Carbon::parse($candidate)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanSummary(string $value): string
    {
        return Str::limit($this->cleanText($value), 420, '...');
    }

    private function cleanText(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(strip_tags($decoded));

        return preg_replace('/\s+/u', ' ', $stripped) ?: '';
    }

    private function extractContentEncoded(\SimpleXMLElement $item): string
    {
        $namespaces = $item->getNamespaces(true);
        $contentNs = $namespaces['content'] ?? null;

        if ($contentNs === null) {
            return '';
        }

        $contentNode = $item->children($contentNs);

        return trim((string) ($contentNode->encoded ?? ''));
    }

    private function extractRssImage(\SimpleXMLElement $item, string $sourceUrl, string $htmlFallback): ?string
    {
        $namespaces = $item->getNamespaces(true);
        $mediaNs = $namespaces['media'] ?? null;

        if ($mediaNs !== null) {
            $media = $item->children($mediaNs);

            foreach (['content', 'thumbnail'] as $nodeName) {
                if (! isset($media->{$nodeName})) {
                    continue;
                }

                foreach ($media->{$nodeName} as $mediaNode) {
                    $attrs = $mediaNode->attributes();
                    $url = trim((string) ($attrs['url'] ?? ''));

                    if ($url !== '') {
                        return UrlNormalizer::absolute($url, $sourceUrl);
                    }
                }
            }
        }

        foreach ($item->enclosure ?? [] as $enclosure) {
            $attrs = $enclosure->attributes();
            $type = Str::lower(trim((string) ($attrs['type'] ?? '')));
            $url = trim((string) ($attrs['url'] ?? ''));

            if ($url !== '' && ($type === '' || Str::startsWith($type, 'image/'))) {
                return UrlNormalizer::absolute($url, $sourceUrl);
            }
        }

        return $this->extractFirstImageFromHtml($htmlFallback, $sourceUrl);
    }

    private function extractAtomImage(\SimpleXMLElement $entryNode, string $sourceUrl, string $htmlFallback): ?string
    {
        foreach ($entryNode->link as $link) {
            $attributes = $link->attributes();
            $href = trim((string) ($attributes['href'] ?? ''));
            $rel = Str::lower((string) ($attributes['rel'] ?? ''));
            $type = Str::lower((string) ($attributes['type'] ?? ''));

            if (
                $href !== '' &&
                ($rel === 'enclosure' || $rel === 'preview') &&
                ($type === '' || Str::startsWith($type, 'image/'))
            ) {
                return UrlNormalizer::absolute($href, $sourceUrl);
            }
        }

        return $this->extractFirstImageFromHtml($htmlFallback, $sourceUrl);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function extractJsonFeedImage(array $item, string $sourceUrl): ?string
    {
        $direct = $item['image'] ?? $item['banner_image'] ?? null;

        if (is_string($direct) && trim($direct) !== '') {
            return UrlNormalizer::absolute(trim($direct), $sourceUrl);
        }

        $attachments = $item['attachments'] ?? null;

        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $mimeType = Str::lower((string) ($attachment['mime_type'] ?? ''));
                $url = trim((string) ($attachment['url'] ?? ''));

                if ($url !== '' && Str::startsWith($mimeType, 'image/')) {
                    return UrlNormalizer::absolute($url, $sourceUrl);
                }
            }
        }

        $html = is_string($item['content_html'] ?? null) ? $item['content_html'] : '';

        return $this->extractFirstImageFromHtml($html, $sourceUrl);
    }

    private function extractFirstImageFromHtml(string $html, string $sourceUrl): ?string
    {
        $candidate = trim($html);

        if ($candidate === '' || stripos($candidate, '<img') === false) {
            return null;
        }

        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($candidate);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//img[@src]');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $src = trim((string) ($nodes->item(0)?->attributes?->getNamedItem('src')?->textContent ?? ''));

        if ($src === '') {
            return null;
        }

        return $this->sanitizeImageUrl(UrlNormalizer::absolute($src, $sourceUrl));
    }

    /**
     * @return list<ParsedArticleData>
     */
    private function parseWithActiveSchema(string $payload, string $sourceUrl): array
    {
        $source = $this->sourceByUrl($sourceUrl);

        if ($source === null) {
            return [];
        }

        /** @var ParserSchema|null $schema */
        $schema = $source->activeParserSchema()->first();

        if ($schema === null) {
            return [];
        }

        $schemaPayload = (array) ($schema->schema_payload ?? []);
        $articleXPath = (string) ($schemaPayload['article_xpath'] ?? $schemaPayload['article_selector'] ?? '');
        $titleXPath = (string) ($schemaPayload['title_xpath'] ?? $schemaPayload['title_selector'] ?? '');
        $linkXPath = (string) ($schemaPayload['link_xpath'] ?? $schemaPayload['link_selector'] ?? '');

        if ($articleXPath === '' || $titleXPath === '' || $linkXPath === '') {
            return [];
        }

        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($payload);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);

        try {
            $articleNodes = $xpath->query($articleXPath);
        } catch (\Throwable) {
            return [];
        }

        if ($articleNodes === false || $articleNodes->length === 0) {
            return [];
        }

        $summaryXPath = (string) ($schemaPayload['summary_xpath'] ?? './/p[1]');
        $imageXPath = (string) ($schemaPayload['image_xpath'] ?? './/img[1]/@src');
        $dateXPath = (string) ($schemaPayload['date_xpath'] ?? './/time/@datetime|.//time');
        $results = [];

        foreach ($articleNodes as $articleNode) {
            $title = $this->cleanText($this->extractXPathValue($xpath, $titleXPath, $articleNode));
            $linkRaw = $this->extractXPathValue($xpath, $linkXPath, $articleNode);

                if ($title === '' || $linkRaw === '' || ! $this->isLikelyArticleTitle($title)) {
                continue;
            }

            $summary = $this->cleanSummary($this->extractXPathValue($xpath, $summaryXPath, $articleNode));
                $summary = $this->isLikelyArticleSummary($summary) ? $summary : '';
            $imageRaw = $this->extractXPathValue($xpath, $imageXPath, $articleNode);
            $imageUrl = $imageRaw !== ''
                ? $this->sanitizeImageUrl(UrlNormalizer::absolute($imageRaw, $sourceUrl))
                : null;
            $dateRaw = $this->extractXPathValue($xpath, $dateXPath, $articleNode);

            $results[] = new ParsedArticleData(
                url: UrlNormalizer::absolute($linkRaw, $sourceUrl),
                title: $title,
                externalId: null,
                summary: $summary !== '' ? $summary : null,
                imageUrl: $imageUrl,
                publishedAt: $this->toIso8601($dateRaw),
                meta: [
                    'source_type' => 'html_schema',
                    'parser_schema_id' => $schema->id,
                    'parser_schema_version' => $schema->version,
                ],
            );
        }

        $deduplicated = $this->deduplicateByUrl($results);
        $filtered = $this->filterLikelyArticleCandidates($deduplicated, $sourceUrl);


        if ($filtered !== []) {
            return $filtered;
        }

        return $deduplicated;
    }

    private function isLikelyArticleTitle(string $value): bool
    {
        $title = Str::lower(trim($value));
        $length = mb_strlen($title);

        if ($length < 12 || $length > 220) {
            return false;
        }

        foreach (['read more', 'learn more', 'subscribe', 'sign up', 'log in', 'menu', 'search', 'back to', 'home'] as $blocked) {
            if ($title === $blocked || str_contains($title, $blocked)) {
                return false;
            }
        }

        return true;
    }

    private function isLikelyArticleSummary(string $value): bool
    {
        $summary = Str::lower(trim($value));

        if ($summary === '') {
            return false;
        }

        if (mb_strlen($summary) < 24) {
            return false;
        }

        foreach (['subscribe', 'privacy', 'cookie', 'sign up', 'log in'] as $blocked) {
            if (str_contains($summary, $blocked)) {
                return false;
            }
        }

        return true;
    }

    private function sanitizeImageUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $value = Str::lower(trim($url));

        foreach (['avatar', 'profile', 'author', 'user', 'logo', 'icon', 'emoji', 'favicon', 'gravatar', 'sprite', 'badge', 'placeholder'] as $blocked) {
            if (str_contains($value, $blocked)) {
                return null;
            }
        }

        // Reject URLs whose path has no recognizable image extension — these
        // are often dynamic media endpoints that serve video or other non-image
        // content (e.g. cdn-dynmedia-1.microsoft.com/is/content/…).
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && $path !== '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if ($ext === '' && preg_match('#/is/(?:content|image)/#i', $path) === 1) {
                return null;
            }
        }

        return $url;
    }

    private function sourceByUrl(string $sourceUrl): ?Source
    {
        $normalized = UrlNormalizer::normalize($sourceUrl);
        $hash = hash('sha256', Str::lower($normalized));

        return Source::query()
            ->where('canonical_url_hash', $hash)
            ->orWhere('source_url_hash', $hash)
            ->first();
    }

    private function extractXPathValue(DOMXPath $xpath, string $expression, DOMNode $context): string
    {
        if (trim($expression) === '') {
            return '';
        }

        try {
            $nodes = $xpath->query($expression, $context);
        } catch (\Throwable) {
            return '';
        }

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $first = $nodes->item(0);

        if ($first === null) {
            return '';
        }

        return trim((string) ($first->nodeValue ?? ''));
    }

    /**
     * @param  list<ParsedArticleData>  $articles
     * @return list<ParsedArticleData>
     */
    private function deduplicateByUrl(array $articles): array
    {
        $unique = [];

        foreach ($articles as $article) {
            $key = hash('sha256', Str::lower($article->url));
            $unique[$key] = $article;
        }

        return array_values($unique);
    }

    /**
     * @param  list<ParsedArticleData>  $articles
     * @return list<ParsedArticleData>
     */
    private function filterLikelyArticleCandidates(array $articles, string $sourceUrl): array
    {
        $scored = [];

        foreach ($articles as $article) {
            $score = $this->articleCandidateScore($article, $sourceUrl);

            if ($score < 2) {
                continue;
            }

            $scored[] = [
                'score' => $score,
                'article' => $article,
            ];
        }

        if ($scored === []) {
            return [];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_map(
            static fn (array $row): ParsedArticleData => $row['article'],
            $scored,
        ));
    }

    private function articleCandidateScore(ParsedArticleData $article, string $sourceUrl): int
    {
        $score = 0;
        $normalizedUrl = UrlNormalizer::normalize($article->url);
        $sourceHost = Str::lower((string) (parse_url($sourceUrl, PHP_URL_HOST) ?? ''));
        $articleHost = Str::lower((string) (parse_url($normalizedUrl, PHP_URL_HOST) ?? ''));

        if ($articleHost !== '' && $sourceHost !== '') {
            if ($articleHost === $sourceHost || Str::endsWith($articleHost, '.'.$sourceHost) || Str::endsWith($sourceHost, '.'.$articleHost)) {
                $score += 1;
            } else {
                $score -= 2;
            }
        }

        $path = (string) (parse_url($normalizedUrl, PHP_URL_PATH) ?? '/');
        $path = '/'.trim($path, '/');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $value): bool => $value !== ''));
        $depth = count($segments);

        if ($depth >= 2) {
            $score += 2;
        } elseif ($depth === 1) {
            $score += 0;
        } else {
            $score -= 2;
        }

        if ($path === '/' || $path === '') {
            $score -= 3;
        }

        $lowerPath = Str::lower(trim($path, '/'));
        $blocked = [
            'rss',
            'feed',
            'feeds',
            'news',
            'latest',
            'video',
            'videos',
            'search',
            'tag',
            'tags',
            'topic',
            'topics',
            'category',
            'categories',
            'about',
            'contact',
            'privacy',
            'terms',
            'login',
            'signup',
            'register',
            'podcast',
            'podcasts',
        ];

        if (in_array($lowerPath, $blocked, true)) {
            $score -= 3;
        }

        if ($depth <= 1 && in_array($lowerPath, $blocked, true)) {
            $score -= 2;
        }

        if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp|pdf|xml)$/i', $path) === 1) {
            $score -= 4;
        }

        if ($article->publishedAt !== null) {
            $score += 2;
        }

        $summaryLength = mb_strlen(trim((string) ($article->summary ?? '')));
        $titleLength = mb_strlen(trim($article->title));

        if ($summaryLength >= 40) {
            $score += 1;
        }

        if ($titleLength >= 24) {
            $score += 1;
        } elseif ($titleLength < 10) {
            $score -= 1;
        }

        if (preg_match('#/(20\d{2}|19\d{2})/#', $path) === 1) {
            $score += 1;
        }

        if ($depth >= 2 && str_contains((string) end($segments), '-')) {
            $score += 1;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isJsonFeed(array $payload): bool
    {
        $version = $payload['version'] ?? null;

        return is_string($version) && str_contains(Str::lower($version), 'jsonfeed.org/version');
    }
}
