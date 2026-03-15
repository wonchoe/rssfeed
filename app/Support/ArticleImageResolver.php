<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ArticleImageResolver
{
    public function resolve(string $articleUrl): ?string
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
            fn (): string => $this->resolveWithoutCache($normalized) ?? ''
        );

        if (! is_string($cached) || $cached === '') {
            return null;
        }

        if (! $this->isAcceptableImageUrl($cached)) {
            Cache::forget($cacheKey);
            $recomputed = $this->resolveWithoutCache($normalized);
            Cache::put($cacheKey, $recomputed ?? '', now()->addMinutes($ttlMinutes));

            return $recomputed;
        }

        return $cached;
    }

    private function resolveWithoutCache(string $articleUrl): ?string
    {
        try {
            $response = Http::timeout(max(3, (int) config('ingestion.preview_image_enrichment.timeout_seconds', 6)))
                ->retry(1, 150)
                ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                ->accept('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
                ->get($articleUrl);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        if (trim($body) === '') {
            return null;
        }

        return $this->extractImageFromHtml($body, $articleUrl);
    }

    private function extractImageFromHtml(string $html, string $baseUrl): ?string
    {
        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($html);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $queries = [
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image:url"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image:src"]/@content',
            '//link[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"image_src")]/@href',
            '//article//img[@src][1]/@src',
            '//main//img[@src][1]/@src',
            '//img[@src][1]/@src',
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
            $normalized = $this->normalizeCandidate($value, $baseUrl);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeCandidate(string $value, string $baseUrl): ?string
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

        return $this->isAcceptableImageUrl($absolute) ? $absolute : null;
    }

    private function isAcceptableImageUrl(string $url): bool
    {
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
