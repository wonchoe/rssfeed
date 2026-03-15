<?php

namespace Tests\Unit;

use App\Support\ArticleImageResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleImageResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ingestion.preview_image_enrichment.enabled', true);
        config()->set('ingestion.preview_image_enrichment.ttl_minutes', 120);
        Cache::flush();
    }

    public function test_resolver_extracts_og_image_and_uses_cache(): void
    {
        Http::fake([
            'https://example.com/article' => Http::response(
                '<html><head><meta property="og:image" content="/images/hero.jpg"></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $resolver = app(ArticleImageResolver::class);

        $first = $resolver->resolve('https://example.com/article');
        $second = $resolver->resolve('https://example.com/article');

        $this->assertSame('https://example.com/images/hero.jpg', $first);
        $this->assertSame('https://example.com/images/hero.jpg', $second);
        Http::assertSentCount(1);
    }

    public function test_resolver_ignores_numeric_og_image_width_values(): void
    {
        Http::fake([
            'https://example.com/post' => Http::response(
                <<<'HTML'
<html>
  <head>
    <meta property="og:image:width" content="1200">
    <meta property="og:image" content="https://cdn.example.com/cover.jpg">
  </head>
  <body></body>
</html>
HTML,
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $resolver = app(ArticleImageResolver::class);
        $resolved = $resolver->resolve('https://example.com/post');

        $this->assertSame('https://cdn.example.com/cover.jpg', $resolved);
    }
}
