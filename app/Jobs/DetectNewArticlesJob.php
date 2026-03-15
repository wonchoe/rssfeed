<?php

namespace App\Jobs;

use App\Data\Article\NormalizedArticleData;
use App\Domain\Article\Contracts\DeduplicationService;
use App\Events\NewArticlesDetected;
use App\Models\Article;
use App\Models\Source;
use App\Support\PipelineStage;
use App\Support\SourceHealthTracker;
use App\Support\SourceUsageStateManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DetectNewArticlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 45, 90];
    }

    /**
     * Execute the job.
     */
    public function handle(
        DeduplicationService $deduplicationService,
        SourceHealthTracker $healthTracker,
        SourceUsageStateManager $usageStateManager,
    ): void {
        $normalizedCacheKey = $this->context['normalized_cache_key'] ?? null;

        if (! is_string($normalizedCacheKey) || $normalizedCacheKey === '') {
            return;
        }

        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $healthTracker->markAttempt($source, PipelineStage::Deduplicate->value, $this->context);

        $rows = Cache::get($normalizedCacheKey);

        if (! is_array($rows) || $rows === []) {
            $source->update([
                'status' => 'active',
                'last_parsed_at' => now(),
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'detect' => [
                        'input_count' => 0,
                        'new_articles' => 0,
                        'duplicates_skipped' => 0,
                        'invalid_rows' => 0,
                        'reason' => 'No normalized rows available for deduplication.',
                        'stage' => PipelineStage::Deduplicate->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);
            $healthTracker->markFailure(
                source: $source,
                stage: PipelineStage::Deduplicate->value,
                errorType: 'dedupe_empty_input',
                message: 'No normalized rows available for deduplication.',
                context: $this->context,
            );
            $usageStateManager->refresh($source);

            return;
        }

        $newArticleIds = [];
        $invalidRows = 0;
        $duplicatesSkipped = 0;
        $inputCount = count($rows);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $invalidRows++;

                continue;
            }

            $normalizedArticle = new NormalizedArticleData(
                canonicalUrl: (string) ($row['canonical_url'] ?? ''),
                canonicalUrlHash: (string) ($row['canonical_url_hash'] ?? ''),
                contentHash: (string) ($row['content_hash'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                summary: is_string($row['summary'] ?? null) ? $row['summary'] : null,
                imageUrl: is_string($row['image_url'] ?? null) ? $row['image_url'] : null,
                publishedAt: is_string($row['published_at'] ?? null) ? $row['published_at'] : null,
                meta: is_array($row['meta'] ?? null) ? $row['meta'] : [],
            );

            if (
                $normalizedArticle->canonicalUrl === '' ||
                $normalizedArticle->canonicalUrlHash === '' ||
                $normalizedArticle->contentHash === '' ||
                $normalizedArticle->title === ''
            ) {
                $invalidRows++;

                continue;
            }

            if ($deduplicationService->isDuplicate($normalizedArticle)) {
                $duplicatesSkipped++;

                continue;
            }

            $article = Article::query()->create([
                'source_id' => $source->id,
                'external_id' => $normalizedArticle->meta['external_id'] ?? null,
                'canonical_url_hash' => $normalizedArticle->canonicalUrlHash,
                'canonical_url' => $normalizedArticle->canonicalUrl,
                'content_hash' => $normalizedArticle->contentHash,
                'title' => $normalizedArticle->title,
                'summary' => $normalizedArticle->summary,
                'image_url' => $normalizedArticle->imageUrl,
                'published_at' => $normalizedArticle->publishedAt,
                'discovered_at' => now(),
                'normalized_payload' => $normalizedArticle->meta,
            ]);

            $newArticleIds[] = $article->id;
        }

        $source->update([
            'status' => 'active',
            'last_parsed_at' => now(),
            'last_success_at' => now(),
            'meta' => array_merge((array) ($source->meta ?? []), [
                'detect' => [
                    'input_count' => $inputCount,
                    'new_articles' => count($newArticleIds),
                    'duplicates_skipped' => $duplicatesSkipped,
                    'invalid_rows' => $invalidRows,
                    'reason' => count($newArticleIds) === 0
                        ? ($duplicatesSkipped > 0
                            ? 'All normalized rows are duplicates; no new articles.'
                            : 'No valid new articles detected from normalized rows.')
                        : null,
                    'stage' => PipelineStage::Deduplicate->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
        $healthTracker->markSuccess($source, PipelineStage::Deduplicate->value, [
            'new_articles' => count($newArticleIds),
            'duplicates_skipped' => $duplicatesSkipped,
            'invalid_rows' => $invalidRows,
        ]);
        $usageStateManager->refresh($source);

        if ($newArticleIds === []) {
            return;
        }

        NewArticlesDetected::dispatch(
            sourceId: (string) $source->id,
            newArticleCount: count($newArticleIds),
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Deduplicate->value,
                'article_ids' => $newArticleIds,
            ]),
        );
    }

    public function failed(Throwable $exception): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        app(SourceHealthTracker::class)->markFailure(
            source: $source,
            stage: PipelineStage::Deduplicate->value,
            errorType: 'dedupe_exception',
            message: $exception->getMessage(),
            context: $this->context,
        );

        $source->update([
            'status' => 'detect_failed',
            'meta' => array_merge((array) ($source->meta ?? []), [
                'detect' => [
                    'error' => $exception->getMessage(),
                    'stage' => PipelineStage::Deduplicate->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }
}
