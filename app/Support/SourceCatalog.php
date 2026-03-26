<?php

namespace App\Support;

use App\Models\Source;
use App\Models\SourceAlias;
use Illuminate\Support\Str;

class SourceCatalog
{
    public function normalizeSubmittedUrl(string $url): string
    {
        return UrlNormalizer::normalize($url);
    }

    public function findBySubmittedUrl(string $url): ?Source
    {
        $normalizedUrl = $this->normalizeSubmittedUrl($url);

        if (! UrlNormalizer::isValidHttpUrl($normalizedUrl)) {
            return null;
        }

        return $this->findByNormalizedUrl($normalizedUrl);
    }

    public function findByNormalizedUrl(string $normalizedUrl, bool $includeAliases = true): ?Source
    {
        $hash = hash('sha256', Str::lower($normalizedUrl));

        $query = Source::query()
            ->where('source_url_hash', $hash)
            ->orWhere('canonical_url_hash', $hash);

        if ($includeAliases) {
            $query->orWhereHas('aliases', fn ($aliasQuery) => $aliasQuery->where('normalized_alias_hash', $hash));
        }

        return $query->first();
    }

    public function findOrCreate(string $url, int $pollingIntervalMinutes = 30, bool $includeAliases = true): Source
    {
        $normalizedUrl = $this->normalizeSubmittedUrl($url);
        $existing = $this->findByNormalizedUrl($normalizedUrl, $includeAliases);

        if ($existing !== null) {
            return $existing;
        }

        $host = $this->hostFromUrl($normalizedUrl);
        $pollingIntervalMinutes = max(5, $pollingIntervalMinutes);
        $jitterSeconds = random_int(
            30,
            max(30, (int) config('ingestion.schedule_jitter_seconds', 120))
        );

        return Source::query()->create([
            'source_url_hash' => hash('sha256', Str::lower($normalizedUrl)),
            'source_url' => $normalizedUrl,
            'domain' => $host,
            'host' => $host,
            'source_type' => 'unknown',
            'status' => 'pending',
            'usage_state' => 'inactive',
            'health_score' => 50,
            'health_state' => 'unknown',
            'polling_interval_minutes' => $pollingIntervalMinutes,
            'next_check_at' => now()->addSeconds($jitterSeconds),
        ]);
    }

    public function attachAlias(Source $source, string $submittedUrl): ?SourceAlias
    {
        $normalized = $this->normalizeSubmittedUrl($submittedUrl);

        if (! UrlNormalizer::isValidHttpUrl($normalized)) {
            return null;
        }

        $hash = hash('sha256', Str::lower($normalized));
        $alias = SourceAlias::query()->where('normalized_alias_hash', $hash)->first();

        if ($alias !== null && (int) $alias->source_id !== (int) $source->id) {
            return $alias;
        }

        if ($alias === null) {
            $alias = new SourceAlias([
                'source_id' => $source->id,
                'alias_url' => trim($submittedUrl),
                'normalized_alias_url' => $normalized,
                'normalized_alias_hash' => $hash,
                'first_seen_at' => now(),
            ]);
        } else {
            $alias->fill([
                'alias_url' => trim($submittedUrl),
                'normalized_alias_url' => $normalized,
            ]);
        }

        $alias->last_seen_at = now();
        $alias->save();

        return $alias;
    }

    /**
     * @return list<array{title:string,url:string,summary:?string,image_url:?string,published_at:?string}>
     */
    public function previewItemsFromCache(Source $source, int $limit = 12): array
    {
        return $source->articles()
            ->latest('published_at')
            ->latest('id')
            ->limit(max(1, $limit))
            ->get(['title', 'canonical_url', 'summary', 'image_url', 'published_at'])
            ->map(static fn ($article): array => [
                'title' => (string) $article->title,
                'url' => (string) $article->canonical_url,
                'summary' => $article->summary !== null
                    ? trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $article->summary, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '')
                    : null,
                'image_url' => $article->image_url !== null ? (string) $article->image_url : null,
                'published_at' => $article->published_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function hasFreshCachedArticles(Source $source, int $freshMinutes): bool
    {
        if (! $source->articles()->exists()) {
            return false;
        }

        $freshMinutes = max(1, $freshMinutes);
        $reference = $source->last_success_at ?? $source->last_parsed_at;

        if ($reference === null) {
            return false;
        }

        return $reference->gte(now()->subMinutes($freshMinutes));
    }

    public function updateSourceHostMeta(Source $source, ?string $url = null): void
    {
        $candidate = $url !== null ? $this->normalizeSubmittedUrl($url) : (string) ($source->canonical_url ?: $source->source_url);
        $host = $this->hostFromUrl($candidate);

        if ($host === null) {
            return;
        }

        $source->update([
            'host' => $source->host ?: $host,
            'domain' => $source->domain ?: $host,
        ]);
    }

    private function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return Str::lower($host);
    }
}
