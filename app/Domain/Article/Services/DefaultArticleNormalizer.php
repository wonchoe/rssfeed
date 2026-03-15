<?php

namespace App\Domain\Article\Services;

use App\Data\Article\NormalizedArticleData;
use App\Data\Parsing\ParsedArticleData;
use App\Domain\Article\Contracts\ArticleNormalizer;
use App\Support\UrlNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DefaultArticleNormalizer implements ArticleNormalizer
{
    public function normalize(ParsedArticleData $article): NormalizedArticleData
    {
        $canonicalUrl = UrlNormalizer::normalize($article->url);
        $canonicalUrlHash = hash('sha256', Str::lower($canonicalUrl));

        $publishedAt = $this->normalizeDate($article->publishedAt);
        $title = $this->sanitizeText($article->title);
        $summary = $this->sanitizeNullableText($article->summary);
        $imageUrl = $article->imageUrl !== null ? UrlNormalizer::normalize($article->imageUrl) : null;

        $contentHash = hash('sha256', Str::lower(implode('|', [
            $canonicalUrl,
            $title,
            $summary ?? '',
            $imageUrl ?? '',
            $publishedAt ?? '',
            $article->externalId ?? '',
        ])));

        return new NormalizedArticleData(
            canonicalUrl: $canonicalUrl,
            canonicalUrlHash: $canonicalUrlHash,
            contentHash: $contentHash,
            title: $title,
            summary: $summary,
            imageUrl: $imageUrl,
            publishedAt: $publishedAt,
            meta: [
                'external_id' => $article->externalId,
                'source_meta' => $article->meta,
            ],
        );
    }

    private function sanitizeText(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(strip_tags($decoded));
        $clean = preg_replace('/\s+/u', ' ', $stripped) ?: '';

        if ($clean === '') {
            return 'Untitled article';
        }

        return Str::limit($clean, 240, '...');
    }

    private function sanitizeNullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(strip_tags($decoded));
        $clean = preg_replace('/\s+/u', ' ', $stripped) ?: '';

        if ($clean === '') {
            return null;
        }

        return Str::limit($clean, 420, '...');
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
