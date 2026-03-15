<?php

namespace App\Jobs;

use App\Data\Parsing\ParsedArticleData;
use App\Domain\Article\Contracts\ArticleNormalizer;
use App\Domain\Parsing\Contracts\AiSchemaResolver;
use App\Domain\Parsing\Contracts\FeedParserService;
use App\Domain\Parsing\Contracts\SchemaValidator;
use App\Domain\Source\Contracts\SourceDiscoveryService;
use App\Models\Article;
use App\Models\FeedGeneration;
use App\Models\ParseAttempt;
use App\Models\Source;
use App\Support\ArticleImageResolver;
use App\Support\ParseAttemptTracker;
use App\Support\ParserSchemaRegistry;
use App\Support\PipelineStage;
use App\Support\SourceCatalog;
use App\Support\SourceHealthTracker;
use App\Support\SourceUsageStateManager;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GenerateFeedPreviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public ?int $parseAttemptId = null;

    public function __construct(
        public readonly string $generationId,
        public readonly ?string $runToken = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(
        SourceDiscoveryService $sourceDiscoveryService,
        FeedParserService $feedParserService,
        ArticleNormalizer $articleNormalizer,
        AiSchemaResolver $aiSchemaResolver,
        SchemaValidator $schemaValidator,
        SourceCatalog $sourceCatalog,
        SourceUsageStateManager $usageStateManager,
        SourceHealthTracker $healthTracker,
        ParseAttemptTracker $attemptTracker,
        ParserSchemaRegistry $schemaRegistry,
        ArticleImageResolver $articleImageResolver,
    ): void {
        $generation = FeedGeneration::query()->find($this->generationId);

        if ($generation === null) {
            return;
        }

        $meta = (array) ($generation->meta ?? []);

        if ($this->runToken !== null && ($meta['run_token'] ?? null) !== $this->runToken) {
            return;
        }

        if (! in_array($generation->status, ['queued', 'discovering', 'fetching', 'parsing'], true)) {
            return;
        }

        $attempt = null;
        $stalePreviewItems = [];

        try {
            $generation->update([
                'status' => 'discovering',
                'message' => 'Discovering feed source...',
                'started_at' => now(),
            ]);

            $requestedUrl = UrlNormalizer::normalize($generation->requested_url);

            if (! UrlNormalizer::isValidHttpUrl($requestedUrl)) {
                $this->markFailed(
                    $generation,
                    'Invalid URL. Please provide a valid HTTP or HTTPS URL.',
                    ['error_type' => 'invalid_url'],
                );

                return;
            }

            $existingSource = $sourceCatalog->findByNormalizedUrl($requestedUrl);

            if ($existingSource !== null) {
                $sourceCatalog->attachAlias($existingSource, $generation->requested_url);
                $usageStateManager->refresh($existingSource);

                if ($sourceCatalog->hasFreshCachedArticles(
                    $existingSource,
                    (int) config('ingestion.cached_source_fresh_minutes', 45),
                )) {
                    $previewItems = $sourceCatalog->previewItemsFromCache($existingSource, 12);
                    $previewItems = $this->enrichCachedPreviewItems(
                        source: $existingSource,
                        previewItems: $previewItems,
                        imageResolver: $articleImageResolver,
                    );

                    if ($previewItems !== []) {
                        $healthTracker->markSuccess($existingSource, PipelineStage::Cache->value, [
                            'generation_id' => $generation->id,
                            'cache_mode' => 'fresh',
                            'cached_articles' => count($previewItems),
                        ]);

                        $generation->update([
                            'status' => 'ready',
                            'message' => 'Loaded fresh cached articles from source history.',
                            'source_id' => $existingSource->id,
                            'resolved_url' => $existingSource->canonical_url ?: $existingSource->source_url,
                            'source_type' => $existingSource->source_type,
                            'preview_items' => $previewItems,
                            'finished_at' => now(),
                            'meta' => array_merge((array) ($generation->meta ?? []), [
                                'cache_hit' => true,
                                'cache_mode' => 'fresh',
                                'cached_articles' => count($previewItems),
                                'last_success_at' => $existingSource->last_success_at?->toIso8601String(),
                            ]),
                        ]);

                        return;
                    }
                }

                $stalePreviewItems = $sourceCatalog->previewItemsFromCache($existingSource, 12);
                $stalePreviewItems = $this->enrichCachedPreviewItems(
                    source: $existingSource,
                    previewItems: $stalePreviewItems,
                    imageResolver: $articleImageResolver,
                );

                if ($stalePreviewItems !== []) {
                    $generation->update([
                        'status' => 'discovering',
                        'message' => 'Cached snapshot is stale. Refreshing source now...',
                        'source_id' => $existingSource->id,
                        'resolved_url' => $existingSource->canonical_url ?: $existingSource->source_url,
                        'source_type' => $existingSource->source_type,
                        'meta' => array_merge((array) ($generation->meta ?? []), [
                            'cache_mode' => 'stale_revalidate',
                            'cached_articles' => count($stalePreviewItems),
                            'last_success_at' => $existingSource->last_success_at?->toIso8601String(),
                        ]),
                    ]);
                }
            }

            $discovery = $sourceDiscoveryService->discover($requestedUrl);
            $candidate = $discovery->primaryCandidate;

            if ($candidate === null) {
                if ($existingSource !== null && $stalePreviewItems !== []) {
                    $this->serveStaleCacheFallback(
                        generation: $generation,
                        source: $existingSource,
                        stalePreviewItems: $stalePreviewItems,
                        message: 'Showing cached snapshot. Source discovery failed during refresh.',
                        extraMeta: [
                            'error_type' => 'feed_not_discovered',
                            'warnings' => $discovery->warnings,
                        ],
                    );

                    return;
                }

                $this->markFailed($generation, 'No supported feed was discovered for this URL.', [
                    'error_type' => 'feed_not_discovered',
                    'warnings' => $discovery->warnings,
                ]);

                return;
            }

            $resolvedUrl = UrlNormalizer::normalize($candidate->canonicalUrl ?? $candidate->url);
            $sourceType = $candidate->type;
            $source = $existingSource ?? $sourceCatalog->findOrCreate($resolvedUrl);
            $sourceCatalog->attachAlias($source, $generation->requested_url);
            $sourceCatalog->attachAlias($source, $resolvedUrl);
            $sourceCatalog->updateSourceHostMeta($source, $resolvedUrl);
            $healthTracker->markAttempt($source, PipelineStage::GeneratePreview->value, [
                'generation_id' => $generation->id,
            ]);
            $attempt = $attemptTracker->start(
                source: $source,
                stage: PipelineStage::GeneratePreview->value,
                context: [
                    'generation_id' => $generation->id,
                    ...($this->runToken !== null ? ['run_token' => $this->runToken] : []),
                ],
                schema: $schemaRegistry->activeSchemaFor($source),
            );
            $this->parseAttemptId = $attempt->id;

            $generation->update([
                'status' => 'fetching',
                'message' => 'Fetching source payload...',
                'resolved_url' => $resolvedUrl,
                'source_type' => $sourceType,
                'source_id' => $source->id,
                'meta' => array_merge((array) ($generation->meta ?? []), [
                    'warnings' => $discovery->warnings,
                    'confidence' => $candidate->confidence,
                ]),
            ]);

            $response = Http::retry(
                (int) config('ingestion.fetch.retry_times', 2),
                (int) config('ingestion.fetch.retry_sleep_ms', 300),
            )
                ->timeout((int) config('ingestion.fetch.timeout_seconds', 20))
                ->withUserAgent((string) config('ingestion.fetch.user_agent'))
                ->accept('*/*')
                ->get($resolvedUrl);

            if (! $response->successful()) {
                $statusCode = $response->status();

                $healthTracker->markFailure(
                    source: $source,
                    stage: PipelineStage::Fetch->value,
                    errorType: $this->errorTypeForHttpStatus($statusCode),
                    message: 'Resolved source returned HTTP '.$statusCode.'.',
                    httpStatus: $statusCode,
                    context: ['generation_id' => $generation->id],
                );
                if ($attempt !== null) {
                    $attemptTracker->markFailure(
                        attempt: $attempt,
                        errorType: $this->errorTypeForHttpStatus($statusCode),
                        errorMessage: 'Resolved source returned HTTP '.$statusCode.'.',
                        extra: [
                            'http_status' => $statusCode,
                            'generation_id' => $generation->id,
                        ],
                    );
                }

                if ($existingSource !== null && $stalePreviewItems !== []) {
                    $this->serveStaleCacheFallback(
                        generation: $generation,
                        source: $existingSource,
                        stalePreviewItems: $stalePreviewItems,
                        message: 'Showing cached snapshot. Refresh failed with HTTP '.$statusCode.'.',
                        extraMeta: [
                            'error_type' => 'http_fetch_failed',
                            'status_code' => $statusCode,
                        ],
                    );

                    return;
                }

                $this->markFailed($generation, $this->httpFailureMessage($statusCode), [
                    'error_type' => 'http_fetch_failed',
                    'status_code' => $response->status(),
                ]);

                return;
            }

            $generation->update([
                'status' => 'parsing',
                'message' => 'Parsing feed payload...',
                'meta' => array_merge((array) ($generation->meta ?? []), [
                    'status_code' => $response->status(),
                    'payload_bytes' => strlen($response->body()),
                ]),
            ]);

            $parsed = $feedParserService->parse(
                payload: $response->body(),
                sourceUrl: $resolvedUrl,
                sourceType: $sourceType,
            );
            $parsed = $this->enrichParsedArticlesWithImages($parsed, $articleImageResolver);

            $aiPreviewMeta = null;

            if ($parsed === [] && in_array($sourceType, ['html', 'unknown'], true)) {
                $aiFallback = $this->attemptAiPreviewFallback(
                    source: $source,
                    payload: $response->body(),
                    resolvedUrl: $resolvedUrl,
                    feedParserService: $feedParserService,
                    aiSchemaResolver: $aiSchemaResolver,
                    schemaValidator: $schemaValidator,
                    schemaRegistry: $schemaRegistry,
                );

                $parsed = $this->enrichParsedArticlesWithImages(
                    $aiFallback['parsed'],
                    $articleImageResolver,
                );
                $aiPreviewMeta = $aiFallback['meta'];
            }

            if ($parsed === [] && $existingSource !== null && $stalePreviewItems !== []) {
                $this->serveStaleCacheFallback(
                    generation: $generation,
                    source: $existingSource,
                    stalePreviewItems: $stalePreviewItems,
                    message: 'Showing cached snapshot. Refresh parsed zero items.',
                    extraMeta: [
                        'error_type' => 'parse_empty',
                        ...($aiPreviewMeta !== null ? ['ai_preview' => $aiPreviewMeta] : []),
                    ],
                );

                return;
            }

            $snapshot = $attempt !== null
                ? $attemptTracker->captureSnapshot(
                    attempt: $attempt,
                    payload: $response->body(),
                    headers: $response->headers(),
                    finalUrl: $resolvedUrl,
                    snapshotKind: 'preview_payload',
                )
                : null;

            $previewItems = [];

            foreach (array_slice($parsed, 0, 12) as $item) {
                $previewItems[] = [
                    'title' => $item->title,
                    'url' => $item->url,
                    'summary' => $item->summary,
                    'image_url' => $item->imageUrl,
                    'published_at' => $item->publishedAt,
                ];
            }

            $persistedCount = $this->persistPreviewArticles(
                source: $source,
                parsedArticles: $parsed,
                articleNormalizer: $articleNormalizer,
            );

            $source->update([
                'source_type' => $sourceType,
                'status' => $previewItems === [] ? 'active' : 'parsed',
                'canonical_url' => $resolvedUrl,
                'canonical_url_hash' => hash('sha256', Str::lower($resolvedUrl)),
                'last_parsed_at' => now(),
            ]);

            $healthTracker->markSuccess($source, PipelineStage::GeneratePreview->value, [
                'generation_id' => $generation->id,
                'preview_count' => count($previewItems),
                'persisted_articles' => $persistedCount,
            ]);
            if ($attempt !== null) {
                $attemptTracker->markSuccess($attempt, [
                    'http_status' => $response->status(),
                    'generation_id' => $generation->id,
                    'parsed_count' => count($previewItems),
                    'persisted_articles' => $persistedCount,
                    'snapshot_id' => $snapshot?->id,
                ]);
            }
            $usageStateManager->refresh($source);

            $generationMessage = $previewItems === []
                ? 'Preview generated, but no items were parsed.'
                : 'Preview generated successfully.';

            if ($previewItems === [] && is_array($aiPreviewMeta)) {
                $generationMessage = match ((string) ($aiPreviewMeta['status'] ?? '')) {
                    'invalid_schema' => 'AI fallback analyzed this page, but schema was invalid.',
                    'resolver_exception' => 'AI fallback failed due to a resolver error.',
                    'schema_saved_but_no_items' => 'AI schema was generated, but no news items were extracted yet.',
                    'low_quality_schema' => 'AI schema was generated but rejected due to low extraction quality.',
                    default => $generationMessage,
                };
            }

            $generation->update([
                'status' => 'ready',
                'message' => $generationMessage,
                'source_id' => $source?->id,
                'preview_items' => $previewItems,
                'finished_at' => now(),
                'meta' => array_merge((array) ($generation->meta ?? []), [
                    'parsed_count' => count($previewItems),
                    'persisted_articles' => $persistedCount,
                    ...($stalePreviewItems !== [] ? [
                        'cache_hit' => false,
                        'cache_mode' => 'refreshed_live',
                    ] : []),
                    ...($aiPreviewMeta !== null ? ['ai_preview' => $aiPreviewMeta] : []),
                ]),
            ]);
        } catch (Throwable $exception) {
            if ($attempt !== null) {
                $attemptTracker->markFailure(
                    attempt: $attempt,
                    errorType: 'generate_preview_exception',
                    errorMessage: $exception->getMessage(),
                    extra: [
                        'generation_id' => $generation->id,
                    ],
                );
            }

            $this->markFailed($generation, 'Generation failed: '.$exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function markFailed(FeedGeneration $generation, string $message, array $extra = []): void
    {
        $generation->update([
            'status' => 'failed',
            'message' => $message,
            'finished_at' => now(),
            'meta' => array_merge((array) ($generation->meta ?? []), $extra),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $generation = FeedGeneration::query()->find($this->generationId);

        if ($generation === null) {
            return;
        }

        $attempt = $this->parseAttemptId !== null
            ? ParseAttempt::query()->find($this->parseAttemptId)
            : null;

        if ($attempt !== null) {
            app(ParseAttemptTracker::class)->markFailure(
                attempt: $attempt,
                errorType: 'generate_preview_failed_callback',
                errorMessage: $exception->getMessage(),
                extra: [
                    'generation_id' => $generation->id,
                ],
            );
        }

        $this->markFailed($generation, 'Generation failed: '.$exception->getMessage(), [
            'failed_stage' => 'generate_feed_preview',
        ]);
    }

    /**
     * @param  list<ParsedArticleData>  $parsedArticles
     */
    private function persistPreviewArticles(
        Source $source,
        array $parsedArticles,
        ArticleNormalizer $articleNormalizer,
    ): int {
        $persisted = 0;

        foreach ($parsedArticles as $parsedArticle) {
            $normalized = $articleNormalizer->normalize($parsedArticle);

            if (
                trim($normalized->canonicalUrl) === '' ||
                trim($normalized->canonicalUrlHash) === '' ||
                trim($normalized->title) === ''
            ) {
                continue;
            }

            $existing = Article::query()
                ->where('canonical_url_hash', $normalized->canonicalUrlHash)
                ->first();

            if ($existing === null) {
                Article::query()->create([
                    'source_id' => $source->id,
                    'external_id' => $normalized->meta['external_id'] ?? null,
                    'canonical_url_hash' => $normalized->canonicalUrlHash,
                    'canonical_url' => $normalized->canonicalUrl,
                    'content_hash' => $normalized->contentHash,
                    'title' => $normalized->title,
                    'summary' => $normalized->summary,
                    'image_url' => $normalized->imageUrl,
                    'published_at' => $normalized->publishedAt,
                    'discovered_at' => now(),
                    'normalized_payload' => $normalized->meta,
                ]);

                $persisted++;

                continue;
            }

            $existing->update([
                'source_id' => $source->id,
                'content_hash' => $normalized->contentHash,
                'title' => $normalized->title,
                'summary' => $normalized->summary,
                'image_url' => $normalized->imageUrl,
                'published_at' => $normalized->publishedAt,
                'normalized_payload' => $normalized->meta,
            ]);
        }

        return $persisted;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function queueSourceRefresh(Source $source, array $context = []): void
    {
        $needsDiscovery = in_array($source->source_type, ['unknown', 'html'], true)
            || in_array($source->status, ['pending', 'discovery_failed', 'parse_failed', 'normalize_failed', 'detect_failed'], true)
            || $source->canonical_url === null;

        if ($needsDiscovery) {
            DiscoverSourceTypeJob::dispatch((string) $source->id, $context)->onQueue('ingestion');

            return;
        }

        FetchSourceJob::dispatch((string) $source->id, $context)->onQueue('ingestion');
    }

    private function httpFailureMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Source request was rejected as malformed (HTTP 400).',
            401, 403 => 'Source temporarily blocks automated access (HTTP '.$statusCode.').',
            404 => 'Source URL was not found (HTTP 404).',
            408 => 'Source timed out while fetching (HTTP 408).',
            429 => 'Source is rate-limiting requests (HTTP 429). Please retry later.',
            default => $statusCode >= 500
                ? 'Source server is temporarily unavailable (HTTP '.$statusCode.').'
                : 'Resolved source returned HTTP '.$statusCode.'.',
        };
    }

    private function errorTypeForHttpStatus(int $statusCode): string
    {
        return match (true) {
            $statusCode === 429 => 'http_429',
            $statusCode === 403 => 'http_403',
            $statusCode >= 500 => 'http_5xx',
            default => 'http_'.$statusCode,
        };
    }

    /**
     * @param  list<array{title:string,url:string,summary:?string,image_url:?string,published_at:?string}>  $stalePreviewItems
     * @param  array<string, mixed>  $extraMeta
     */
    private function serveStaleCacheFallback(
        FeedGeneration $generation,
        Source $source,
        array $stalePreviewItems,
        string $message,
        array $extraMeta = [],
    ): void {
        $generation->update([
            'status' => 'ready',
            'message' => $message,
            'source_id' => $source->id,
            'resolved_url' => $source->canonical_url ?: $source->source_url,
            'source_type' => $source->source_type,
            'preview_items' => $stalePreviewItems,
            'finished_at' => now(),
            'meta' => array_merge((array) ($generation->meta ?? []), [
                'cache_hit' => true,
                'cache_mode' => 'stale_fallback',
                'cached_articles' => count($stalePreviewItems),
                'last_success_at' => $source->last_success_at?->toIso8601String(),
            ], $extraMeta),
        ]);
    }

    /**
     * @return array{parsed:list<ParsedArticleData>,meta:array<string,mixed>}
     */
    private function attemptAiPreviewFallback(
        Source $source,
        string $payload,
        string $resolvedUrl,
        FeedParserService $feedParserService,
        AiSchemaResolver $aiSchemaResolver,
        SchemaValidator $schemaValidator,
        ParserSchemaRegistry $schemaRegistry,
    ): array {
        if ((bool) config('ingestion.ai_repair_enabled', true) === false) {
            return [
                'parsed' => [],
                'meta' => [
                    'attempted' => false,
                    'status' => 'disabled',
                ],
            ];
        }

        try {
            $candidate = $aiSchemaResolver->resolve($payload, $resolvedUrl);
        } catch (Throwable $exception) {
            return [
                'parsed' => [],
                'meta' => [
                    'attempted' => true,
                    'status' => 'resolver_exception',
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        $validation = $schemaValidator->validate($candidate);
        $candidateValid = (bool) ($candidate['valid'] ?? true);

        if (! $validation->valid || ! $candidateValid) {
            return [
                'parsed' => [],
                'meta' => [
                    'attempted' => true,
                    'status' => 'invalid_schema',
                    'strategy' => $candidate['strategy'] ?? null,
                    'model' => $candidate['model'] ?? null,
                    'errors' => $validation->errors,
                ],
            ];
        }

        $confidence = is_numeric($candidate['confidence'] ?? null)
            ? (float) $candidate['confidence']
            : null;

        $schema = $schemaRegistry->activateCustomSchema(
            source: $source,
            strategyType: 'ai_xpath_schema',
            schemaPayload: array_merge($candidate, [
                'resolved_from' => 'preview_fallback',
                'resolved_at' => now()->toIso8601String(),
            ]),
            confidence: $confidence,
            createdBy: 'ai_generated_preview',
        );

        $parsed = $feedParserService->parse(
            payload: $payload,
            sourceUrl: $resolvedUrl,
            sourceType: 'html',
        );

        $minItems = max(1, (int) config('ingestion.ai_preview_min_items', 4));

        if (count($parsed) < $minItems) {
            $schema->update([
                'is_active' => false,
                'is_shadow' => true,
            ]);

            return [
                'parsed' => [],
                'meta' => [
                    'attempted' => true,
                    'status' => 'low_quality_schema',
                    'strategy' => $candidate['strategy'] ?? null,
                    'model' => $candidate['model'] ?? null,
                    'confidence' => $candidate['confidence'] ?? null,
                    'schema_id' => $schema->id,
                    'parsed_count' => count($parsed),
                    'min_items_required' => $minItems,
                ],
            ];
        }

        $meta = [
            'attempted' => true,
            'status' => $parsed === [] ? 'schema_saved_but_no_items' : 'parsed_with_ai_schema',
            'strategy' => $candidate['strategy'] ?? null,
            'model' => $candidate['model'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'schema_id' => $schema->id,
            'parsed_count' => count($parsed),
        ];

        $source->update([
            'meta' => array_merge((array) ($source->meta ?? []), [
                'ai_repair' => [
                    'status' => $meta['status'],
                    'schema_id' => $schema->id,
                    'confidence' => $candidate['confidence'] ?? null,
                    'strategy' => $candidate['strategy'] ?? null,
                    'model' => $candidate['model'] ?? null,
                    'last_requested_at' => now()->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        return [
            'parsed' => $parsed,
            'meta' => $meta,
        ];
    }

    /**
     * @param  list<ParsedArticleData>  $parsedArticles
     * @return list<ParsedArticleData>
     */
    private function enrichParsedArticlesWithImages(array $parsedArticles, ArticleImageResolver $imageResolver): array
    {
        $maxLookups = max(0, (int) config('ingestion.preview_image_enrichment.max_items', 12));

        if ($maxLookups === 0 || $parsedArticles === []) {
            return $parsedArticles;
        }

        $lookups = 0;
        $enriched = [];

        foreach ($parsedArticles as $article) {
            if (
                $article->imageUrl === null &&
                $lookups < $maxLookups &&
                trim($article->url) !== ''
            ) {
                $resolvedImage = $imageResolver->resolve($article->url);
                $lookups++;

                if ($resolvedImage !== null) {
                    $enriched[] = new ParsedArticleData(
                        url: $article->url,
                        title: $article->title,
                        externalId: $article->externalId,
                        summary: $article->summary,
                        imageUrl: $resolvedImage,
                        publishedAt: $article->publishedAt,
                        meta: array_merge($article->meta, ['image_enriched' => true]),
                    );

                    continue;
                }
            }

            $enriched[] = $article;
        }

        return $enriched;
    }

    /**
     * @param  list<array{title:string,url:string,summary:?string,image_url:?string,published_at:?string}>  $previewItems
     * @return list<array{title:string,url:string,summary:?string,image_url:?string,published_at:?string}>
     */
    private function enrichCachedPreviewItems(
        Source $source,
        array $previewItems,
        ArticleImageResolver $imageResolver,
    ): array {
        $maxLookups = max(0, (int) config('ingestion.preview_image_enrichment.max_items', 12));

        if ($maxLookups === 0 || $previewItems === []) {
            return $previewItems;
        }

        $lookups = 0;
        $updatedImages = [];

        foreach ($previewItems as $index => $item) {
            $currentImage = is_string($item['image_url'] ?? null) ? trim((string) $item['image_url']) : '';
            $articleUrl = trim((string) ($item['url'] ?? ''));

            if ($currentImage !== '' || $articleUrl === '' || $lookups >= $maxLookups) {
                continue;
            }

            $resolvedImage = $imageResolver->resolve($articleUrl);
            $lookups++;

            if ($resolvedImage === null) {
                continue;
            }

            $previewItems[$index]['image_url'] = $resolvedImage;
            $updatedImages[$articleUrl] = $resolvedImage;
        }

        if ($updatedImages === []) {
            return $previewItems;
        }

        foreach ($updatedImages as $articleUrl => $imageUrl) {
            $articleHash = hash('sha256', Str::lower(UrlNormalizer::normalize($articleUrl)));

            Article::query()
                ->where('source_id', $source->id)
                ->where('canonical_url_hash', $articleHash)
                ->where(function ($query): void {
                    $query->whereNull('image_url')->orWhere('image_url', '');
                })
                ->update([
                    'image_url' => $imageUrl,
                ]);
        }

        return $previewItems;
    }
}
