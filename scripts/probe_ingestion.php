<?php

declare(strict_types=1);

use App\Domain\Parsing\Contracts\FeedParserService;
use App\Domain\Source\Contracts\SourceDiscoveryService;
use App\Models\Source;
use App\Support\ParserSchemaRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$urls = [
    'https://github.blog/changelog',
    'https://github.blog',
    'https://blog.cloudflare.com',
    'https://openai.com/news',
    'https://slack.com/blog/news',
    'https://www.anthropic.com/news',
    'https://blog.jetbrains.com',
    'https://www.npr.org',
    'https://www.theverge.com',
    'https://laravel.com/blog',
];

$discovery = app(SourceDiscoveryService::class);
$feedParser = app(FeedParserService::class);
$schemaRegistry = app(ParserSchemaRegistry::class);

$results = [];

foreach ($urls as $url) {
    $row = [
        'requested_url' => $url,
        'primary_type' => null,
        'primary_url' => null,
        'primary_strategy' => null,
        'primary_count' => null,
        'fallback_used' => false,
        'fallback_type' => null,
        'fallback_url' => null,
        'fallback_count' => null,
        'warnings' => [],
        'error' => null,
    ];

    $tempSource = null;

    try {
        $result = $discovery->discover($url);
        $primary = $result->primaryCandidate;

        if ($primary === null) {
            $row['error'] = 'no_candidate';
            $results[] = $row;
            continue;
        }

        $primaryUrl = $primary->canonicalUrl ?? $primary->url;
        $row['primary_type'] = $primary->type;
        $row['primary_url'] = $primaryUrl;
        $row['primary_strategy'] = $primary->meta['strategy'] ?? null;
        $row['warnings'] = $result->warnings;

        $response = Http::timeout((int) config('ingestion.fetch.timeout_seconds', 20))
            ->withUserAgent((string) config('ingestion.fetch.user_agent'))
            ->accept('*/*')
            ->get($primaryUrl);

        if (! $response->successful()) {
            $row['error'] = 'primary_http_'.$response->status();
            $results[] = $row;
            continue;
        }

        $payload = $response->body();
        $articles = [];

        if ($primary->type === 'html' && is_array($primary->meta['schema_payload'] ?? null)) {
            $tempSource = Source::query()->create([
                'source_url_hash' => hash('sha256', strtolower($primaryUrl)),
                'source_url' => $primaryUrl,
                'canonical_url_hash' => hash('sha256', strtolower($primaryUrl)),
                'canonical_url' => $primaryUrl,
                'source_type' => 'html',
                'status' => 'active',
                'usage_state' => 'active',
                'health_score' => 100,
                'health_state' => 'healthy',
                'polling_interval_minutes' => 60,
            ]);

            $schemaRegistry->activateCustomSchema(
                source: $tempSource,
                strategyType: 'deterministic_html_schema',
                schemaPayload: array_merge((array) $primary->meta['schema_payload'], [
                    'source_type' => 'html',
                    'requested_url' => $url,
                    'resolved_url' => $primaryUrl,
                ]),
                confidence: $primary->confidence,
                createdBy: 'probe',
            );
        }

        $articles = $feedParser->parse($payload, $primaryUrl, $primary->type);
        $row['primary_count'] = count($articles);

        if ($articles === []) {
            foreach ($result->candidates as $candidate) {
                if (! in_array($candidate->type, ['rss', 'atom', 'json_feed'], true)) {
                    continue;
                }

                $candidateUrl = $candidate->canonicalUrl ?? $candidate->url;

                if ($candidateUrl === $primaryUrl) {
                    continue;
                }

                $fallbackResponse = Http::timeout((int) config('ingestion.fetch.timeout_seconds', 20))
                    ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                    ->accept('*/*')
                    ->get($candidateUrl);

                if (! $fallbackResponse->successful()) {
                    continue;
                }

                $fallbackArticles = $feedParser->parse($fallbackResponse->body(), $candidateUrl, $candidate->type);

                if ($fallbackArticles === []) {
                    continue;
                }

                $row['fallback_used'] = true;
                $row['fallback_type'] = $candidate->type;
                $row['fallback_url'] = $candidateUrl;
                $row['fallback_count'] = count($fallbackArticles);
                break;
            }
        }
    } catch (Throwable $exception) {
        $row['error'] = $exception->getMessage();
    } finally {
        if ($tempSource !== null) {
            $tempSource->parserSchemas()->delete();
            $tempSource->delete();
        }
    }

    $results[] = $row;
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;