<?php

namespace App\Jobs;

use App\Events\NewArticlesDetected;
use App\Models\Article;
use App\Models\Source;
use App\Support\ArticlePageEnricher;
use App\Support\PipelineStage;
use App\Support\SourceHealthTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EnrichNewArticlesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  list<int>  $articleIds
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $articleIds,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(ArticlePageEnricher $enricher, SourceHealthTracker $healthTracker): void
    {
        if (! (bool) config('ingestion.article_enrichment.enabled', true)) {
            $this->fireNewArticlesDetected();

            return;
        }

        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $maxPerBatch = max(1, (int) config('ingestion.article_enrichment.max_articles_per_batch', 20));
        $ids = array_slice($this->articleIds, 0, $maxPerBatch);

        $articles = Article::query()
            ->whereIn('id', $ids)
            ->get();

        $enrichedCount = 0;

        foreach ($articles as $article) {
            /** @var Article $article */
            if (! is_string($article->canonical_url) || $article->canonical_url === '') {
                continue;
            }

            $sourceType = (string) data_get($article->normalized_payload, 'source_meta.source_type', '');
            $shouldPreferOgMetadata = in_array($sourceType, ['html_schema', 'html'], true);

            $needsImage = $article->image_url === null
                || $article->image_url === ''
                || (is_string($article->image_url) && ! $enricher->isAcceptableImageUrl($article->image_url));
            $needsSummary = $article->summary === null || $article->summary === '';
            $needsTitle = $shouldPreferOgMetadata;

            if (! $needsImage && ! $needsSummary && ! $needsTitle) {
                continue;
            }

            try {
                $enrichment = $enricher->enrich($article->canonical_url);
            } catch (Throwable) {
                continue;
            }

            $updates = [];

            if ($needsImage) {
                $existingIsEmpty = $article->image_url === null || $article->image_url === '';
                $enrichedImage = $enrichment->imageUrl !== null && $enricher->isAcceptableImageUrl($enrichment->imageUrl)
                    ? $enrichment->imageUrl
                    : null;

                if ($enrichedImage !== null) {
                    $updates['image_url'] = $enrichedImage; // Upgrade to a verified good image
                } elseif (! $existingIsEmpty) {
                    $updates['image_url'] = null; // Clear the unacceptable existing image
                }
            }

            if ($needsSummary && $enrichment->description !== null) {
                $updates['summary'] = $enrichment->description;
            }

            if ($needsTitle && $enrichment->title !== null && $enrichment->title !== '') {
                $updates['title'] = $enrichment->title;
            }

            if (! $enrichment->hasUsefulData() && $updates === []) {
                continue;
            }

            if ($updates !== []) {
                $article->update($updates);
                $enrichedCount++;
            }
        }

        $healthTracker->markSuccess($source, PipelineStage::Enrich->value, [
            'enriched_articles' => $enrichedCount,
            'total_articles' => count($ids),
        ]);

        if (! ($this->context['skip_delivery'] ?? false)) {
            $this->fireNewArticlesDetected();
        }
    }

    private function fireNewArticlesDetected(): void
    {
        NewArticlesDetected::dispatch(
            sourceId: $this->sourceId,
            newArticleCount: count($this->articleIds),
            context: array_merge($this->context, [
                'pipeline_stage' => PipelineStage::Enrich->value,
                'article_ids' => $this->articleIds,
            ]),
        );
    }

    public function failed(Throwable $exception): void
    {
        // Even if enrichment fails, fire the event so delivery still happens (unless skip_delivery)
        if (! ($this->context['skip_delivery'] ?? false)) {
            $this->fireNewArticlesDetected();
        }
    }
}
