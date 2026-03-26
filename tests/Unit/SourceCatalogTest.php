<?php

namespace Tests\Unit;

use App\Models\Source;
use App\Models\SourceAlias;
use App\Support\SourceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_alias_matches_can_be_ignored_for_explicit_page_urls(): void
    {
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog/feed'),
            'source_url' => 'https://github.blog/feed',
            'canonical_url_hash' => hash('sha256', 'https://github.blog/feed'),
            'canonical_url' => 'https://github.blog/feed',
            'source_type' => 'rss',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 100,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
        ]);

        SourceAlias::query()->create([
            'source_id' => $source->id,
            'alias_url' => 'https://github.blog/changelog',
            'normalized_alias_url' => 'https://github.blog/changelog',
            'normalized_alias_hash' => hash('sha256', 'https://github.blog/changelog'),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $catalog = app(SourceCatalog::class);

        $this->assertSame($source->id, $catalog->findByNormalizedUrl('https://github.blog/changelog')?->id);
        $this->assertNull($catalog->findByNormalizedUrl('https://github.blog/changelog', includeAliases: false));
    }
}