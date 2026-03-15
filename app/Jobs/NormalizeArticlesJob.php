<?php

namespace App\Jobs;

use App\Data\Parsing\ParsedArticleData;
use App\Domain\Article\Contracts\ArticleNormalizer;
use App\Models\Source;
use App\Support\PipelineStage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class NormalizeArticlesJob implements ShouldQueue
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
        return [15, 45, 120];
    }

    /**
     * Execute the job.
     */
    public function handle(ArticleNormalizer $articleNormalizer): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $parsedCacheKey = $this->context['parsed_cache_key'] ?? null;

        if (! is_string($parsedCacheKey) || $parsedCacheKey === '') {
            $source->update([
                'status' => 'normalize_skipped',
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'normalize' => [
                        'reason' => 'Parsed cache key is missing.',
                        'stage' => PipelineStage::Normalize->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return;
        }

        $parsedRows = Cache::get($parsedCacheKey);

        if (! is_array($parsedRows) || $parsedRows === []) {
            $source->update([
                'status' => 'active',
                'last_parsed_at' => now(),
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'normalize' => [
                        'input_count' => 0,
                        'normalized_count' => 0,
                        'reason' => 'No parsed rows available for normalization.',
                        'stage' => PipelineStage::Normalize->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'detect' => [
                        'input_count' => 0,
                        'new_articles' => 0,
                        'reason' => 'No parsed rows; nothing to deduplicate.',
                        'stage' => PipelineStage::Deduplicate->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return;
        }

        $normalizedRows = [];
        $invalidInputRows = 0;

        foreach ($parsedRows as $row) {
            if (! is_array($row)) {
                $invalidInputRows++;

                continue;
            }

            $parsedArticle = new ParsedArticleData(
                url: (string) ($row['url'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                externalId: is_string($row['external_id'] ?? null) ? $row['external_id'] : null,
                summary: is_string($row['summary'] ?? null) ? $row['summary'] : null,
                imageUrl: is_string($row['image_url'] ?? null) ? $row['image_url'] : null,
                publishedAt: is_string($row['published_at'] ?? null) ? $row['published_at'] : null,
                meta: is_array($row['meta'] ?? null) ? $row['meta'] : [],
            );

            if ($parsedArticle->url === '' || $parsedArticle->title === '') {
                $invalidInputRows++;

                continue;
            }

            $normalized = $articleNormalizer->normalize($parsedArticle);

            $normalizedRows[] = [
                'canonical_url' => $normalized->canonicalUrl,
                'canonical_url_hash' => $normalized->canonicalUrlHash,
                'content_hash' => $normalized->contentHash,
                'title' => $normalized->title,
                'summary' => $normalized->summary,
                'image_url' => $normalized->imageUrl,
                'published_at' => $normalized->publishedAt,
                'meta' => $normalized->meta,
            ];
        }

        $inputCount = count($parsedRows);
        $normalizedCount = count($normalizedRows);

        $normalizedCacheKey = sprintf(
            'ingestion:normalized:%s:%s',
            $this->sourceId,
            hash('sha256', $parsedCacheKey),
        );

        Cache::put(
            $normalizedCacheKey,
            $normalizedRows,
            now()->addMinutes((int) config('ingestion.normalized_cache_ttl_minutes', 60)),
        );

        $source->update([
            'status' => $normalizedCount === 0 ? 'active' : 'normalized',
            'last_parsed_at' => $normalizedCount === 0 ? now() : $source->last_parsed_at,
            'meta' => array_merge((array) ($source->meta ?? []), [
                'normalize' => [
                    'input_count' => $inputCount,
                    'normalized_count' => $normalizedCount,
                    'invalid_input_rows' => $invalidInputRows,
                    'cache_key' => $normalizedCacheKey,
                    'reason' => $normalizedCount === 0
                        ? 'Normalization produced zero valid rows.'
                        : null,
                    'stage' => PipelineStage::Normalize->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        if ($normalizedCount === 0) {
            $source->update([
                'meta' => array_merge((array) ($source->meta ?? []), [
                    'detect' => [
                        'input_count' => 0,
                        'new_articles' => 0,
                        'reason' => 'No normalized rows; deduplication skipped.',
                        'stage' => PipelineStage::Deduplicate->value,
                        'updated_at' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            return;
        }

        DetectNewArticlesJob::dispatch(
            sourceId: $this->sourceId,
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Deduplicate->value,
                'normalized_cache_key' => $normalizedCacheKey,
            ]),
        )->onQueue('ingestion');
    }

    public function failed(Throwable $exception): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $source->update([
            'status' => 'normalize_failed',
            'meta' => array_merge((array) ($source->meta ?? []), [
                'normalize' => [
                    'error' => $exception->getMessage(),
                    'stage' => PipelineStage::Normalize->value,
                    'updated_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }
}
