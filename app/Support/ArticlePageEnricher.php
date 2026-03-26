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
     * Fetch an article page and extract og:image + og:description (with caching).
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

        /** @var array{image_url: ?string, description: ?string}|null $cached */
        $cached = Cache::remember(
            $cacheKey,
            now()->addMinutes($ttlMinutes),
            fn (): array => $this->enrichWithoutCache($normalized),
        );

        if (! is_array($cached)) {
            return new ArticleEnrichmentData;
        }

        return new ArticleEnrichmentData(
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
     * @return array{image_url: ?string, description: ?string}
     */
    private function enrichWithoutCache(string $articleUrl): array
    {
        $empty = ['image_url' => null, 'description' => null];

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
     * @return array{image_url: ?string, description: ?string}
     */
    private function extractFromHtml(string $html, string $baseUrl): array
    {
        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($html);

        if (! $loaded) {
            return ['image_url' => null, 'description' => null];
        }

        $xpath = new DOMXPath($dom);

        return [
            'image_url' => $this->extractImage($xpath, $baseUrl),
            'description' => $this->extractDescription($xpath),
        ];
    }

    private function extractImage(DOMXPath $xpath, string $baseUrl): ?string
    {
        $queries = [
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image:url"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image:src"]/@content',
            '//link[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"image_src")]/@href',
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
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:description"]/@content',
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

        if ($clean === '' || mb_strlen($clean) < 20) {
            return null;
        }

        return Str::limit($clean, 420, '...');
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
                return false;
            }

            $contentType = Str::lower($response->header('Content-Type') ?? '');

            return str_starts_with($contentType, 'image/');
        } catch (Throwable) {
            // Network error — give it the benefit of the doubt
            return true;
        }
    }

    public function isAcceptableImageUrl(string $url): bool
    {
        $lower = Str::lower($url);

        foreach (['avatar', 'profile', 'author', 'user', 'logo', 'icon', 'emoji', 'favicon', 'gravatar', 'sprite', 'badge', 'placeholder'] as $blocked) {
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

        $basename = basename($path);

        if ($basename !== '' && preg_match('/^\d+$/', $basename) === 1) {
            return false;
        }

        return true;
    }
}
