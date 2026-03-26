<?php

namespace Tests\Feature;

use App\Jobs\EnrichNewArticlesJob;
use App\Models\Article;
use App\Models\Source;
use App\Support\ArticlePageEnricher;
use App\Support\SourceHealthTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HtmlArticleOgEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_articles_are_enriched_from_detail_page_og_tags(): void
    {
        Http::fake([
            'https://github.blog/security/supply-chain-security/a-year-of-open-source-vulnerability-trends-cves-advisories-and-malware/' => Http::response(
                <<<'HTML'
<!doctype html>
<html>
<head>
    <meta property="og:title" content="A year of open source vulnerability trends, CVEs, advisories, and malware" />
    <meta property="og:description" content="GitHub looked back at a year of open source vulnerability activity and the signals maintainers should actually watch." />
    <meta property="og:image" content="https://github.blog/wp-content/uploads/2026/03/og-vulnerability-trends.png" />
</head>
<body></body>
</html>
HTML,
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://github.blog/wp-content/uploads/2026/03/og-vulnerability-trends.png' => Http::response('', 200, ['Content-Type' => 'image/png']),
        ]);

        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog'),
            'source_url' => 'https://github.blog',
            'canonical_url_hash' => hash('sha256', 'https://github.blog'),
            'canonical_url' => 'https://github.blog',
            'source_type' => 'html',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 100,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'canonical_url_hash' => hash('sha256', 'https://github.blog/security/supply-chain-security/a-year-of-open-source-vulnerability-trends-cves-advisories-and-malware/'),
            'canonical_url' => 'https://github.blog/security/supply-chain-security/a-year-of-open-source-vulnerability-trends-cves-advisories-and-malware/',
            'content_hash' => hash('sha256', 'placeholder'),
            'title' => 'A year of open source vulnerability trends',
            'summary' => null,
            'image_url' => null,
            'published_at' => now(),
            'discovered_at' => now(),
            'normalized_payload' => [
                'source_meta' => [
                    'source_type' => 'html_schema',
                ],
            ],
        ]);

        $job = new EnrichNewArticlesJob(
            sourceId: (string) $source->id,
            articleIds: [$article->id],
            context: ['skip_delivery' => true],
        );

        $job->handle(app(ArticlePageEnricher::class), app(SourceHealthTracker::class));

        $article->refresh();

        $this->assertSame(
            'A year of open source vulnerability trends, CVEs, advisories, and malware',
            $article->title,
        );
        $this->assertSame(
            'GitHub looked back at a year of open source vulnerability activity and the signals maintainers should actually watch.',
            $article->summary,
        );
        $this->assertSame(
            'https://github.blog/wp-content/uploads/2026/03/og-vulnerability-trends.png',
            $article->image_url,
        );
    }
}