<?php

namespace App\Domain\Source\Services;

use App\Data\Source\SourceCandidateData;
use App\Data\Source\SourceDiscoveryResultData;
use App\Domain\Parsing\Services\HeuristicHtmlPatternDetector;
use App\Domain\Source\Contracts\SourceDiscoveryService;
use App\Support\UrlNormalizer;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class HttpSourceDiscoveryService implements SourceDiscoveryService
{
    public function __construct(
        private ?HeuristicHtmlPatternDetector $htmlPatternDetector = null,
    ) {
        $this->htmlPatternDetector ??= app(HeuristicHtmlPatternDetector::class);
    }

    public function discover(string $sourceUrl): SourceDiscoveryResultData
    {
        $requestedUrl = UrlNormalizer::normalize($sourceUrl);
        $warnings = [];
        $candidates = [];

        try {
            $response = Http::timeout((int) config('ingestion.discovery.timeout_seconds', 15))
                ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                ->accept('application/rss+xml, application/atom+xml, application/feed+json, text/html, application/xml, text/xml, */*')
                ->get($requestedUrl);

            if (! $response->successful()) {
                $warnings[] = 'Discovery request returned HTTP '.$response->status().'.';
            } else {
                $body = $response->body();
                $contentType = Str::lower((string) $response->header('Content-Type', ''));

                $direct = $this->detectDirectFeedCandidate($requestedUrl, $body, $contentType);

                if ($direct !== null) {
                    $candidates[] = $direct;
                }

                if ($this->looksLikeHtml($contentType, $body)) {
                    $htmlCandidate = $this->detectHtmlListingCandidate($requestedUrl, $body);

                    if ($htmlCandidate !== null) {
                        $candidates[] = $htmlCandidate;
                        $warnings[] = 'Deterministic HTML listing pattern detected.';
                    }

                    $htmlCandidates = $this->discoverFromHtml($requestedUrl, $body);
                    $candidates = [...$candidates, ...$htmlCandidates];

                    if ($htmlCandidates !== []) {
                        $warnings[] = 'Feed autodiscovery used from HTML link tags.';
                    }
                }
            }
        } catch (Throwable $exception) {
            $warnings[] = 'Discovery request failed: '.$exception->getMessage();
        }

        if ($this->shouldProbeFeedEndpoints($candidates)) {
            $probedCandidates = $this->probeCommonFeedEndpoints($requestedUrl);

            if ($probedCandidates !== []) {
                $candidates = [...$candidates, ...$probedCandidates];
                $warnings[] = 'Feed discovered via common endpoint probing.';
            }
        }

        return $this->withFallbackCandidates($requestedUrl, $candidates, $warnings);
    }

    /**
     * @param  list<SourceCandidateData>  $candidates
     * @param  list<string>  $warnings
     */
    private function withFallbackCandidates(string $requestedUrl, array $candidates, array $warnings): SourceDiscoveryResultData
    {
        if ($candidates === []) {
            $fallback = $this->heuristicFromUrl($requestedUrl);

            if ($fallback !== null) {
                $candidates[] = $fallback;
            }
        }

        usort($candidates, fn (SourceCandidateData $a, SourceCandidateData $b): int => $this->score($b) <=> $this->score($a));

        return new SourceDiscoveryResultData(
            requestedUrl: $requestedUrl,
            primaryCandidate: $candidates[0] ?? null,
            candidates: $candidates,
            warnings: $warnings,
        );
    }

    private function score(SourceCandidateData $candidate): int
    {
        $score = ($this->typeWeight($candidate->type) * 1000) + (int) round($candidate->confidence * 100);

        if ($candidate->type === 'html' && is_array($candidate->meta['schema_payload'] ?? null)) {
            $score += 5500;
        }

        return $score;
    }

    private function typeWeight(string $type): int
    {
        return match ($type) {
            'rss' => 6,
            'atom' => 5,
            'json_feed' => 4,
            'html_autodiscovery' => 3,
            'html' => 2,
            default => 1,
        };
    }

    /**
     * @param  list<SourceCandidateData>  $candidates
     */
    private function shouldProbeFeedEndpoints(array $candidates): bool
    {
        if (! (bool) config('ingestion.discovery.feed_probe_enabled', true)) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate->type, ['rss', 'atom', 'json_feed'], true)) {
                return false;
            }
        }

        return true;
    }

    private function detectDirectFeedCandidate(string $requestedUrl, string $body, string $contentType): ?SourceCandidateData
    {
        $payload = trim($body);

        if ($payload === '') {
            return null;
        }

        $json = json_decode($payload, true);

        if (is_array($json) && $this->isJsonFeed($json)) {
            return new SourceCandidateData(
                url: $requestedUrl,
                type: 'json_feed',
                confidence: 0.98,
                title: is_string($json['title'] ?? null) ? $json['title'] : null,
                canonicalUrl: $requestedUrl,
                meta: ['strategy' => 'content_inspection'],
            );
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($payload, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($xml !== false) {
            $root = Str::lower($xml->getName());

            if ($root === 'rss' || $root === 'rdf') {
                return new SourceCandidateData(
                    url: $requestedUrl,
                    type: 'rss',
                    confidence: 0.99,
                    canonicalUrl: $requestedUrl,
                    meta: ['strategy' => 'content_inspection'],
                );
            }

            if ($root === 'feed') {
                return new SourceCandidateData(
                    url: $requestedUrl,
                    type: 'atom',
                    confidence: 0.99,
                    canonicalUrl: $requestedUrl,
                    meta: ['strategy' => 'content_inspection'],
                );
            }
        }

        if ($this->looksLikeHtml($contentType, $payload)) {
            return new SourceCandidateData(
                url: $requestedUrl,
                type: 'html',
                confidence: 0.45,
                canonicalUrl: $requestedUrl,
                meta: ['strategy' => 'content_inspection'],
            );
        }

        return null;
    }

    private function looksLikeHtml(string $contentType, string $body): bool
    {
        if (Str::contains($contentType, 'text/html')) {
            return true;
        }

        $snippet = Str::lower(substr($body, 0, 2048));

        return str_contains($snippet, '<html') || str_contains($snippet, '<head') || str_contains($snippet, '<body');
    }

    /**
     * @return list<SourceCandidateData>
     */
    private function discoverFromHtml(string $requestedUrl, string $html): array
    {
        $dom = new DOMDocument;
        $loaded = @$dom->loadHTML($html);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//link[contains(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "alternate")][@href]');

        if ($nodes === false) {
            return [];
        }

        $candidates = [];

        foreach ($nodes as $node) {
            $type = Str::lower((string) $node->attributes?->getNamedItem('type')?->textContent);
            $href = trim((string) $node->attributes?->getNamedItem('href')?->textContent);
            $title = trim((string) $node->attributes?->getNamedItem('title')?->textContent);

            if ($href === '') {
                continue;
            }

            $discoveredUrl = UrlNormalizer::absolute($href, $requestedUrl);
            $candidateType = match ($type) {
                'application/rss+xml', 'application/rdf+xml' => 'rss',
                'application/atom+xml' => 'atom',
                'application/feed+json', 'application/json' => 'json_feed',
                default => null,
            };

            if ($candidateType === null) {
                continue;
            }

            $candidates[] = new SourceCandidateData(
                url: $discoveredUrl,
                type: $candidateType,
                confidence: 0.87,
                title: $title !== '' ? $title : null,
                canonicalUrl: $discoveredUrl,
                meta: ['strategy' => 'html_autodiscovery', 'from_url' => $requestedUrl],
            );
        }

        return $candidates;
    }

    private function heuristicFromUrl(string $url): ?SourceCandidateData
    {
        $value = Str::lower($url);

        if (str_contains($value, 'atom')) {
            return new SourceCandidateData(
                url: $url,
                type: 'atom',
                confidence: 0.6,
                canonicalUrl: $url,
                meta: ['strategy' => 'url_heuristic'],
            );
        }

        if (str_contains($value, 'json') || str_contains($value, '.feed')) {
            return new SourceCandidateData(
                url: $url,
                type: 'json_feed',
                confidence: 0.6,
                canonicalUrl: $url,
                meta: ['strategy' => 'url_heuristic'],
            );
        }

        if (str_contains($value, 'rss') || str_contains($value, 'feed')) {
            return new SourceCandidateData(
                url: $url,
                type: 'rss',
                confidence: 0.6,
                canonicalUrl: $url,
                meta: ['strategy' => 'url_heuristic'],
            );
        }

        return new SourceCandidateData(
            url: $url,
            type: 'unknown',
            confidence: 0.35,
            canonicalUrl: $url,
            meta: ['strategy' => 'fallback_unknown'],
        );
    }

    private function detectHtmlListingCandidate(string $requestedUrl, string $html): ?SourceCandidateData
    {
        $schema = $this->htmlPatternDetector?->detect($html, $requestedUrl);

        if (! is_array($schema) || ! (bool) ($schema['valid'] ?? false)) {
            return null;
        }

        $confidence = is_numeric($schema['confidence'] ?? null)
            ? (float) $schema['confidence']
            : 0.7;

        return new SourceCandidateData(
            url: $requestedUrl,
            type: 'html',
            confidence: max(0.62, min(0.99, $confidence)),
            canonicalUrl: $requestedUrl,
            meta: [
                'strategy' => 'html_pattern_detector',
                'schema_payload' => $schema,
            ],
        );
    }

    /**
     * @return list<SourceCandidateData>
     */
    private function probeCommonFeedEndpoints(string $requestedUrl): array
    {
        $host = Str::lower((string) (parse_url($requestedUrl, PHP_URL_HOST) ?? ''));

        if ($host === '') {
            return [];
        }

        $probes = [];
        $hostMap = (array) config('ingestion.discovery.feed_probe_host_map', []);
        $hostSpecific = $this->hostSpecificProbeUrls($host, $hostMap);

        foreach ($hostSpecific as $url) {
            if (is_string($url) && trim($url) !== '') {
                $probes[] = UrlNormalizer::normalize($url);
            }
        }

        $paths = (array) config('ingestion.discovery.feed_probe_paths', []);

        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $probes[] = UrlNormalizer::absolute($path, $requestedUrl);
        }

        $probes = array_values(array_unique($probes));
        $maxAttempts = max(1, (int) config('ingestion.discovery.feed_probe_max_attempts', 6));
        $timeoutSeconds = max(3, (int) config('ingestion.discovery.feed_probe_timeout_seconds', 8));

        $candidates = [];

        foreach (array_slice($probes, 0, $maxAttempts) as $probeUrl) {
            try {
                $response = Http::timeout($timeoutSeconds)
                    ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                    ->accept('application/rss+xml, application/atom+xml, application/feed+json, application/xml, text/xml, */*')
                    ->get($probeUrl);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $contentType = Str::lower((string) $response->header('Content-Type', ''));
            $body = $response->body();
            $candidate = $this->detectDirectFeedCandidate($probeUrl, $body, $contentType);

            if ($candidate === null || ! in_array($candidate->type, ['rss', 'atom', 'json_feed'], true)) {
                continue;
            }

            $candidates[] = new SourceCandidateData(
                url: $candidate->url,
                type: $candidate->type,
                confidence: max(0.72, (float) $candidate->confidence),
                title: $candidate->title,
                canonicalUrl: $candidate->canonicalUrl,
                meta: array_merge((array) $candidate->meta, [
                    'strategy' => 'common_feed_probe',
                    'probe_url' => $probeUrl,
                ]),
            );
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $hostMap
     * @return list<string>
     */
    private function hostSpecificProbeUrls(string $host, array $hostMap): array
    {
        $urls = [];

        foreach ($hostMap as $hostPattern => $values) {
            if (! is_string($hostPattern) || ! is_array($values)) {
                continue;
            }

            $pattern = Str::lower(trim($hostPattern));
            $matches = $pattern === $host || Str::endsWith($host, '.'.$pattern);

            if (! $matches) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $urls[] = $value;
                }
            }
        }

        return $urls;
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
