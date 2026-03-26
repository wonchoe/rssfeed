<?php

namespace App\Support;

use App\Data\Article\ArticleEnrichmentData;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ArticlePageEnricher
{
    /**
    * Fetch an article page and extract og:title + og:image + og:description (with caching).
     */
    public function enrich(string $articleUrl): ArticleEnrichmentData
    {
        if (! (bool) config('ingestion.article_enrichment.enabled', true)) {
            return new ArticleEnrichmentData;
        }

        $normalized = UrlNormalizer::normalize($articleUrl);

        if (! UrlNormalizer::isValidHttpUrl($normalized)) {
            return new ArticleEnrichmentData;
        }

        $cacheKey = 'ingestion:enrichment:'.hash('sha256', Str::lower($normalized));
        $ttlMinutes = max(10, (int) config('ingestion.article_enrichment.ttl_minutes', 720));

        /** @var array{title: ?string, image_url: ?string, description: ?string}|null $cached */
        $cached = Cache::remember(
            $cacheKey,
            now()->addMinutes($ttlMinutes),
            fn (): array => $this->enrichWithoutCache($normalized),
        );

        if (! is_array($cached)) {
            return new ArticleEnrichmentData;
        }

        return new ArticleEnrichmentData(
            title: is_string($cached['title'] ?? null) && $cached['title'] !== '' ? $cached['title'] : null,
            imageUrl: is_string($cached['image_url'] ?? null) && $cached['image_url'] !== '' ? $cached['image_url'] : null,
            description: is_string($cached['description'] ?? null) && $cached['description'] !== '' ? $cached['description'] : null,
        );
    }

    /**
     * Backwards-compatible image-only resolver (used by GenerateFeedPreviewJob).
     * Respects the preview_image_enrichment config, not article_enrichment.
     */
    public function resolveImage(string $articleUrl): ?string
    {
        if (! (bool) config('ingestion.preview_image_enrichment.enabled', true)) {
            return null;
        }

        $normalized = UrlNormalizer::normalize($articleUrl);

        if (! UrlNormalizer::isValidHttpUrl($normalized)) {
            return null;
        }

        $cacheKey = 'ingestion:image:'.hash('sha256', Str::lower($normalized));
        $ttlMinutes = max(10, (int) config('ingestion.preview_image_enrichment.ttl_minutes', 720));

        $cached = Cache::remember(
            $cacheKey,
            now()->addMinutes($ttlMinutes),
            fn () => $this->enrichWithoutCache($normalized)['image_url'],
        );

        return is_string($cached) && $cached !== '' ? $cached : null;
    }

    /**
      * @return array{title: ?string, image_url: ?string, description: ?string}
     */
    private function enrichWithoutCache(string $articleUrl): array
    {
          $empty = ['title' => null, 'image_url' => null, 'description' => null];

        try {
            $response = Http::timeout(max(3, (int) config('ingestion.article_enrichment.timeout_seconds', 6)))
                ->retry(1, 150)
                ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                ->accept('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
                ->get($articleUrl);
        } catch (Throwable) {
            return $empty;
        }

        if (! $response->successful()) {
            return $empty;
        }

        $body = $response->body();

        if (trim($body) === '') {
            return $empty;
        }

        return $this->extractFromHtml($body, $articleUrl);
    }

    /**
     * @return array{title: ?string, image_url: ?string, description: ?string}
     */
    private function extractFromHtml(string $html, string $baseUrl): array
    {
        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($html);

        if (! $loaded) {
            return ['title' => null, 'image_url' => null, 'description' => null];
        }

        $xpath = new DOMXPath($dom);

        $title = $this->extractTitle($xpath, $baseUrl);
        $description = $this->extractDescription($xpath);

        if ($description === null) {
            $description = $this->extractArticleParagraphFallback($xpath);
        }

        return [
            'title' => $title,
            'image_url' => $this->extractImage($xpath, $baseUrl),
            'description' => $description,
        ];
    }

    private function extractTitle(DOMXPath $xpath, string $baseUrl): ?string
    {
        $queries = [
            '//meta[@property="og:title"]/@content',
            '//meta[@name="og:title"]/@content',
            '//meta[@name="twitter:title"]/@content',
            '//title/text()',
        ];

        foreach ($queries as $query) {
            try {
                $nodes = $xpath->query($query);
            } catch (Throwable) {
                continue;
            }

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $raw = trim((string) ($nodes->item(0)?->nodeValue ?? ''));

            if ($raw === '') {
                continue;
            }

            $clean = $this->sanitizeTitle($raw, $baseUrl);

            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    private function extractImage(DOMXPath $xpath, string $baseUrl): ?string
    {
        $queries = [
            '//meta[@property="og:image"]/@content',
            '//meta[@property="og:image:url"]/@content',
            '//meta[@property="OG:IMAGE"]/@content',
            '//meta[@name="og:image"]/@content',
            '//meta[@name="twitter:image"]/@content',
            '//meta[@name="twitter:image:src"]/@content',
            '//link[contains(@rel,"image_src")]/@href',
            '//meta[@itemprop="image"]/@content',
            '//img[@srcset][1]/@srcset',
            '//img[@data-src][1]/@data-src',
            '//img[@data-lazy-src][1]/@data-lazy-src',
            '//article//img[@src][1]/@src',
            '//main//img[@src][1]/@src',
        ];

        foreach ($queries as $query) {
            try {
                $nodes = $xpath->query($query);
            } catch (Throwable) {
                continue;
            }

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $value = trim((string) ($nodes->item(0)?->nodeValue ?? ''));

            if (str_contains($query, '@srcset')) {
                $value = $this->extractBestSrcsetCandidate($value);
            }

            $normalized = $this->normalizeImageCandidate($value, $baseUrl);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function extractDescription(DOMXPath $xpath): ?string
    {
        $queries = [
            '//meta[@property="og:description"]/@content',
            '//meta[@name="description"]/@content',
            '//meta[@name="twitter:description"]/@content',
        ];

        foreach ($queries as $query) {
            try {
                $nodes = $xpath->query($query);
            } catch (Throwable) {
                continue;
            }

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $raw = trim((string) ($nodes->item(0)?->nodeValue ?? ''));

            if ($raw === '') {
                continue;
            }

            $clean = $this->sanitizeDescription($raw);

            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    private function sanitizeDescription(string $raw): ?string
    {
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(strip_tags($decoded));
        $clean = preg_replace('/\s+/u', ' ', $stripped) ?: '';

        if ($clean === '' || mb_strlen($clean) < 20 || $this->looksGenericDescription($clean)) {
            return null;
        }

        return Str::limit($clean, 420, '...');
    }

    private function sanitizeTitle(string $raw, string $baseUrl): ?string
    {
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(strip_tags($decoded));
        $clean = preg_replace('/\s+/u', ' ', $stripped) ?: '';
        $clean = $this->stripCommonTitleSuffixes($clean, $baseUrl);

        if ($clean === '' || mb_strlen($clean) < 12 || $this->looksGenericTitle($clean, $baseUrl)) {
            return null;
        }

        return Str::limit($clean, 240, '...');
    }

    private function extractArticleParagraphFallback(DOMXPath $xpath): ?string
    {
        $queries = [
            '//article//p[string-length(normalize-space()) > 80][1]',
            '//main//p[string-length(normalize-space()) > 80][1]',
            '(//p[string-length(normalize-space()) > 80])[1]',
        ];

        foreach ($queries as $query) {
            try {
                $nodes = $xpath->query($query);
            } catch (Throwable) {
                continue;
            }

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $raw = trim((string) ($nodes->item(0)?->textContent ?? ''));
            $clean = $this->sanitizeDescription($raw);

            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    private function extractBestSrcsetCandidate(string $srcset): string
    {
        $candidates = array_values(array_filter(array_map('trim', explode(',', $srcset))));

        if ($candidates === []) {
            return '';
        }

        $best = end($candidates);

        if (! is_string($best) || trim($best) === '') {
            return '';
        }

        return trim((string) preg_split('/\s+/', $best)[0]);
    }

    private function stripCommonTitleSuffixes(string $title, string $baseUrl): string
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $labels = array_values(array_filter(array_unique([
            $host,
            preg_replace('/^www\./i', '', $host) ?: '',
            Str::of($host)->replaceMatches('/^www\./i', '')->before('.')->replace(['-', '_'], ' ')->title()->value(),
            'GitHub Blog',
            'GitHub Changelog',
            'Microsoft Azure Blog',
            'Microsoft SQL Server Blog',
            'Amazon Web Services',
        ])));

        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            $title = preg_replace('/\s*[-|:\x{2013}\x{2014}]\s*'.$quoted.'$/iu', '', $title) ?: $title;
        }

        return trim($title);
    }

    private function looksGenericTitle(string $title, string $baseUrl): bool
    {
        $lower = Str::lower($title);
        $host = Str::lower((string) parse_url($baseUrl, PHP_URL_HOST));
        $siteName = Str::lower((string) Str::of($host)->replaceFirst('www.', '')->before('.')->replace(['-', '_'], ' '));

        if ($lower === $host || $lower === $siteName) {
            return true;
        }

        foreach ([
            'home',
            'homepage',
            'news |',
            'blog |',
            'updates |',
            'microsoft azure',
            'azure updates',
        ] as $blocked) {
            if ($lower === $blocked || str_contains($lower, $blocked.' |')) {
                return true;
            }
        }

        return false;
    }

    private function looksGenericDescription(string $description): bool
    {
        $lower = Str::lower(trim($description));

        foreach ([
            'subscribe to microsoft azure today for service updates',
            'check out the new cloud platform roadmap',
            'the latest news from',
            'learn more about',
            'read more about',
        ] as $blocked) {
            if (str_contains($lower, $blocked)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeImageCandidate(string $value, string $baseUrl): ?string
    {
        if ($value === '' || Str::startsWith(Str::lower($value), 'data:')) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return null;
        }

        $absolute = UrlNormalizer::absolute($value, $baseUrl);

        if (! UrlNormalizer::isValidHttpUrl($absolute)) {
            return null;
        }

        if (! $this->isAcceptableImageUrl($absolute)) {
            return null;
        }

        return $this->verifyImageContentType($absolute) ? $absolute : null;
    }

    /**
     * Lightweight HEAD check to confirm the URL actually serves an image.
     */
    private function verifyImageContentType(string $url): bool
    {
        try {
            $response = Http::timeout(4)
                ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                ->head($url);

            if (! $response->successful()) {
                return $this->verifyImageContentTypeWithGet($url);
            }

            $contentType = Str::lower($response->header('Content-Type') ?? '');

            if (str_starts_with($contentType, 'image/')) {
                return true;
            }

            return $this->verifyImageContentTypeWithGet($url);
        } catch (Throwable) {
            return $this->verifyImageContentTypeWithGet($url);
        }
    }

    private function verifyImageContentTypeWithGet(string $url): bool
    {
        try {
            $response = Http::timeout(6)
                ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                ->withHeaders(['Range' => 'bytes=0-0'])
                ->get($url);

            if (! $response->successful() && $response->status() !== 206) {
                return false;
            }

            $contentType = Str::lower($response->header('Content-Type') ?? '');

            return str_starts_with($contentType, 'image/');
        } catch (Throwable) {
            return false;
        }
    }

    public function isAcceptableImageUrl(string $url): bool
    {
        $lower = Str::lower($url);

        foreach (['avatar', 'profile', 'author', 'user', 'emoji', 'favicon', 'gravatar', 'sprite', 'placeholder'] as $blocked) {
            if (str_contains($lower, $blocked)) {
                return false;
            }
        }

        // Cloudflare cdn-cgi/image/ with face-crop or small dimensions = author avatar
        if (str_contains($lower, 'cdn-cgi/image/') && (
            str_contains($lower, 'gravity=face')
            || str_contains($lower, 'fit=crop')
            || (preg_match('/(?:width|w)=([1-9]\d?)(?:[,&]|$)/i', $url, $m) === 1 && (int) $m[1] < 200)
        )) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && str_contains(Str::lower($host), 'gravatar.com')) {
            return false;
        }

        if (preg_match('/[?&](?:s|size|w|width|h|height)=(?:[1-9]|[1-5]\d)(?:&|$)/i', $url) === 1) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        // Microsoft CDN dynamic endpoints (e.g. /is/content/… or /is/image/…)
        // without a file extension return non-image content (text/plain, video/*).
        if (preg_match('#/is/(?:content|image)/#i', $path) === 1) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === '') {
                return false;
            }
        }

        $basename = basename($path);

        if ($basename !== '' && preg_match('/^\d+$/', $basename) === 1) {
            return false;
        }

        return true;
    }
}
