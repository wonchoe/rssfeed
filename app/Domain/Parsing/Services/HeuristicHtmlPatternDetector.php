<?php

namespace App\Domain\Parsing\Services;

use App\Support\UrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

class HeuristicHtmlPatternDetector
{
    /**
     * @return array<string, mixed>|null
     */
    public function detect(string $html, string $sourceUrl): ?array
    {
        $payload = trim($html);

        if ($payload === '') {
            return null;
        }

        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($payload);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//main//*[self::article or self::li or self::div or self::section][.//a[@href]] | //article[.//a[@href]]');

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $groups = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $title = $this->resolveTitle($xpath, $node);
            $href = $this->resolveHref($xpath, $node);

            if (! $this->isLikelyArticleTitle($title) || ! $this->isLikelyArticleHref($href, $sourceUrl)) {
                continue;
            }

            $signature = $this->signatureForNode($node);

            if ($signature === null) {
                continue;
            }

            $groups[$signature] ??= [
                'count' => 0,
                'title_lengths' => [],
                'with_date' => 0,
                'with_summary' => 0,
                'with_image' => 0,
                'class' => $this->preferredClass($node),
                'tag' => Str::lower($node->tagName),
            ];

            $groups[$signature]['count']++;
            $groups[$signature]['title_lengths'][] = mb_strlen($title);
            $groups[$signature]['with_date'] += $this->hasDate($xpath, $node) ? 1 : 0;
            $groups[$signature]['with_summary'] += $this->hasUsefulSummary($xpath, $node) ? 1 : 0;
            $groups[$signature]['with_image'] += $this->hasUsefulImage($xpath, $node) ? 1 : 0;
        }

        if ($groups === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($groups as $signature => $group) {
            $count = (int) $group['count'];

            if ($count < 3) {
                continue;
            }

            $dateRatio = $count > 0 ? ((int) $group['with_date'] / $count) : 0.0;
            $summaryRatio = $count > 0 ? ((int) $group['with_summary'] / $count) : 0.0;
            $imageRatio = $count > 0 ? ((int) $group['with_image'] / $count) : 0.0;
            $avgTitleLength = $group['title_lengths'] !== []
                ? (array_sum($group['title_lengths']) / count($group['title_lengths']))
                : 0;
            $score = ($count * 10)
                + (int) round($dateRatio * 8)
                + (int) round($summaryRatio * 5)
                + (int) round($imageRatio * 3)
                + ((is_string($group['class']) && $group['class'] !== '') ? 4 : 0)
                + (int) round(min(4, $avgTitleLength / 32));

            if ($score <= $bestScore) {
                continue;
            }

            $bestScore = $score;
            $best = [
                'signature' => $signature,
                ...$group,
                'date_ratio' => $dateRatio,
                'summary_ratio' => $summaryRatio,
                'image_ratio' => $imageRatio,
            ];
        }

        if ($best === null) {
            return null;
        }

        $confidence = min(0.95, 0.46
            + min(0.24, ((int) $best['count']) * 0.035)
            + (((float) $best['date_ratio']) * 0.12)
            + (((float) $best['summary_ratio']) * 0.09)
            + (((float) $best['image_ratio']) * 0.04)
            + ((is_string($best['class']) && $best['class'] !== '') ? 0.08 : 0.0));

        if ($confidence < 0.62) {
            return null;
        }

        $schema = [
            'valid' => true,
            'strategy' => 'heuristic_html_pattern',
            'confidence' => round($confidence, 2),
            'reason' => 'Detected repeated content cards with stable title/link structure.',
            'article_xpath' => $this->articleXPathForGroup($best),
            'title_xpath' => $this->titleXPath(),
            'link_xpath' => $this->linkXPath(),
            'summary_xpath' => $this->summaryXPath(),
            'image_xpath' => $this->imageXPath(),
            'date_xpath' => $this->dateXPath(),
            'source_url' => $sourceUrl,
            'pattern_signature' => $best['signature'],
            'pattern_items_count' => $best['count'],
        ];

        $validItems = $this->validateSchemaOnDom($xpath, $schema, $sourceUrl);

        if ($validItems < max(3, min(5, (int) $best['count']))) {
            return null;
        }

        $schema['pattern_valid_items'] = $validItems;

        return $schema;
    }

    private function resolveTitle(DOMXPath $xpath, DOMElement $node): string
    {
        foreach ([
            './/a[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "title") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "headline")][1]',
            './/*[self::h1 or self::h2 or self::h3 or self::h4]//a[@href][1]',
            './/*[self::h1 or self::h2 or self::h3 or self::h4][1]',
            './/a[@href][1]',
        ] as $expression) {
            try {
                $matches = $xpath->query($expression, $node);
            } catch (\Throwable) {
                continue;
            }

            if ($matches === false || $matches->length === 0) {
                continue;
            }

            $value = $this->cleanText((string) ($matches->item(0)?->textContent ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveHref(DOMXPath $xpath, DOMElement $node): string
    {
        foreach ([
            './/a[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "title") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "headline")][1]/@href',
            './/*[self::h1 or self::h2 or self::h3 or self::h4]//a[@href][1]/@href',
            './/a[@href][1]/@href',
        ] as $expression) {
            try {
                $matches = $xpath->query($expression, $node);
            } catch (\Throwable) {
                continue;
            }

            if ($matches === false || $matches->length === 0) {
                continue;
            }

            $value = trim((string) ($matches->item(0)?->nodeValue ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function hasDate(DOMXPath $xpath, DOMElement $node): bool
    {
        try {
            $matches = $xpath->query('.//time | .//*[@datetime] | .//*[@data-datetime]', $node);
        } catch (\Throwable) {
            return false;
        }

        return $matches !== false && $matches->length > 0;
    }

    private function hasUsefulSummary(DOMXPath $xpath, DOMElement $node): bool
    {
        try {
            $matches = $xpath->query('.//p[string-length(normalize-space()) >= 40][1]', $node);
        } catch (\Throwable) {
            return false;
        }

        if ($matches === false || $matches->length === 0) {
            return false;
        }

        $summary = $this->cleanText((string) ($matches->item(0)?->textContent ?? ''));

        return ! $this->looksLikeBlockedCopy($summary);
    }

    private function hasUsefulImage(DOMXPath $xpath, DOMElement $node): bool
    {
        try {
            $matches = $xpath->query('.//img[@src]', $node);
        } catch (\Throwable) {
            return false;
        }

        if ($matches === false || $matches->length === 0) {
            return false;
        }

        foreach ($matches as $match) {
            $src = $match instanceof DOMElement
                ? Str::lower(trim($match->getAttribute('src')))
                : '';

            if ($src !== '' && ! $this->looksLikeBlockedImage($src)) {
                return true;
            }
        }

        return false;
    }

    private function signatureForNode(DOMElement $node): ?string
    {
        $tag = Str::lower($node->tagName);
        $class = $this->preferredClass($node);

        if ($class !== null) {
            return $tag.':'.$class;
        }

        return in_array($tag, ['article', 'li', 'div', 'section'], true) ? $tag : null;
    }

    private function preferredClass(DOMElement $node): ?string
    {
        $raw = trim($node->getAttribute('class'));

        if ($raw === '') {
            return null;
        }

        $blocked = [
            'active', 'item', 'items', 'card', 'cards', 'content', 'inner', 'wrapper', 'grid', 'list', 'row', 'col', 'container',
        ];

        foreach (preg_split('/\s+/', $raw) ?: [] as $class) {
            $candidate = trim($class);

            if ($candidate === '') {
                continue;
            }

            $lower = Str::lower($candidate);

            if (in_array($lower, $blocked, true)) {
                continue;
            }

            if (preg_match('/^(js-|is-|has-|u-|mt-|pt-|mb-|pb-|d-|col-|row$)/', $lower) === 1) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function articleXPathForGroup(array $group): string
    {
        $class = is_string($group['class'] ?? null) ? trim((string) $group['class']) : '';
        $tag = Str::lower((string) ($group['tag'] ?? 'div'));

        if ($class !== '') {
            return sprintf(
                '//*[contains(concat(" ", normalize-space(@class), " "), " %s ")][.//a[@href] and (.//h1 or .//h2 or .//h3 or .//h4)]',
                $class,
            );
        }

        return match ($tag) {
            'article' => '//article[.//a[@href] and (.//h1 or .//h2 or .//h3 or .//h4)]',
            'li' => '//main//li[.//a[@href] and (.//h1 or .//h2 or .//h3 or .//h4 or .//time)]',
            default => '//main//*[self::div or self::section][.//a[@href] and (.//h1 or .//h2 or .//h3 or .//h4 or .//time)]',
        };
    }

    private function titleXPath(): string
    {
        return './/a[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "title") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "headline")][1] | .//*[self::h1 or self::h2 or self::h3 or self::h4]//a[@href][1] | .//*[self::h1 or self::h2 or self::h3 or self::h4][1]';
    }

    private function linkXPath(): string
    {
        return './/a[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "title") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "headline")][1]/@href | .//*[self::h1 or self::h2 or self::h3 or self::h4]//a[@href][1]/@href | .//a[@href][1]/@href';
    }

    private function summaryXPath(): string
    {
        return './/p[string-length(normalize-space()) >= 40 and not(contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "subscribe")) and not(contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "privacy"))][1]';
    }

    private function imageXPath(): string
    {
        return './/img[@src and not(contains(translate(@src, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "avatar")) and not(contains(translate(@src, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "icon")) and not(contains(translate(@src, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "logo")) and not(contains(translate(@src, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "emoji"))][1]/@src';
    }

    private function dateXPath(): string
    {
        return './/time/@datetime | .//time | .//*[@datetime][1]/@datetime | .//*[@data-datetime][1]/@data-datetime';
    }

    private function isLikelyArticleTitle(string $title): bool
    {
        $length = mb_strlen($title);

        if ($length < 12 || $length > 220) {
            return false;
        }

        return ! $this->looksLikeBlockedCopy($title);
    }

    private function isLikelyArticleHref(string $href, string $sourceUrl): bool
    {
        $value = Str::lower(trim($href));

        if ($value === '' || Str::startsWith($value, ['#', 'javascript:', 'mailto:', 'tel:'])) {
            return false;
        }

        $absolute = Str::lower(UrlNormalizer::absolute($href, $sourceUrl));
        $path = (string) parse_url($absolute, PHP_URL_PATH);

        if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp|ico|pdf|xml)$/i', $path) === 1) {
            return false;
        }

        foreach (['/tag/', '/tags/', '/category/', '/categories/', '/author/', '/about', '/privacy', '/terms', '/contact', '/login', '/signup'] as $blocked) {
            if (str_contains($absolute, $blocked)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeBlockedCopy(string $value): bool
    {
        $lower = Str::lower(trim($value));

        foreach (['read more', 'learn more', 'subscribe', 'sign up', 'privacy', 'terms', 'cookie', 'back to', 'search', 'menu', 'log in'] as $blocked) {
            if ($lower === $blocked || str_contains($lower, $blocked)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeBlockedImage(string $value): bool
    {
        foreach (['avatar', 'profile', 'author', 'user', 'logo', 'icon', 'emoji', 'favicon', 'gravatar', 'sprite', 'badge', 'placeholder'] as $blocked) {
            if (str_contains($value, $blocked)) {
                return true;
            }
        }

        return false;
    }

    private function cleanText(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(strip_tags($decoded));

        return preg_replace('/\s+/u', ' ', $stripped) ?: '';
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function validateSchemaOnDom(DOMXPath $xpath, array $schema, string $sourceUrl): int
    {
        $articleXPath = (string) ($schema['article_xpath'] ?? '');
        $titleXPath = (string) ($schema['title_xpath'] ?? '');
        $linkXPath = (string) ($schema['link_xpath'] ?? '');
        $summaryXPath = (string) ($schema['summary_xpath'] ?? '');
        $dateXPath = (string) ($schema['date_xpath'] ?? '');

        if ($articleXPath === '' || $titleXPath === '' || $linkXPath === '') {
            return 0;
        }

        try {
            $articleNodes = $xpath->query($articleXPath);
        } catch (\Throwable) {
            return 0;
        }

        if ($articleNodes === false || $articleNodes->length === 0) {
            return 0;
        }

        $validItems = 0;

        foreach ($articleNodes as $articleNode) {
            if (! $articleNode instanceof DOMNode) {
                continue;
            }

            $title = $this->cleanText($this->extractValue($xpath, $titleXPath, $articleNode));
            $href = $this->extractValue($xpath, $linkXPath, $articleNode);
            $summary = $this->cleanText($this->extractValue($xpath, $summaryXPath, $articleNode));
            $date = $this->extractValue($xpath, $dateXPath, $articleNode);

            if (! $this->isLikelyArticleTitle($title) || ! $this->isLikelyArticleHref($href, $sourceUrl)) {
                continue;
            }

            if ($summary !== '' && $this->looksLikeBlockedCopy($summary)) {
                continue;
            }

            if ($summary === '' && trim($date) === '') {
                continue;
            }

            $validItems++;
        }

        return $validItems;
    }

    private function extractValue(DOMXPath $xpath, string $expression, DOMNode $context): string
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
}