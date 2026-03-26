<?php

namespace Tests\Unit;

use App\Support\ArticlePageEnricher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticlePageEnricherTest extends TestCase
{
    public function test_enricher_uses_fallback_paragraph_and_strips_site_suffix_from_title(): void
    {
        Http::fake([
            'https://example.com/posts/demo' => Http::response(
                <<<'HTML'
<!doctype html>
<html>
<head>
    <meta property="og:title" content="Deep Infrastructure Lessons - Example.com" />
    <meta property="og:description" content="Learn more about Example.com and our platform." />
    <meta property="og:image" content="https://cdn.example.com/images/demo.jpg" />
</head>
<body>
<main>
    <article>
        <p>This is the first substantial article paragraph with enough detail to serve as a useful fallback summary when the meta description is generic and low-signal for delivery previews.</p>
    </article>
</main>
</body>
</html>
HTML,
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://cdn.example.com/images/demo.jpg' => Http::sequence()
                ->push('', 405, ['Content-Type' => 'text/plain'])
                ->push('', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $result = app(ArticlePageEnricher::class)->enrich('https://example.com/posts/demo');

        $this->assertSame('Deep Infrastructure Lessons', $result->title);
        $this->assertSame(
            'This is the first substantial article paragraph with enough detail to serve as a useful fallback summary when the meta description is generic and low-signal for delivery previews.',
            $result->description,
        );
        $this->assertSame('https://cdn.example.com/images/demo.jpg', $result->imageUrl);
    }

    public function test_enricher_rejects_generic_title_and_description_when_no_real_fallback_exists(): void
    {
        Http::fake([
            'https://azure.example.com/updates?id=1' => Http::response(
                <<<'HTML'
<!doctype html>
<html>
<head>
    <meta property="og:title" content="Azure updates | Microsoft Azure" />
    <meta property="og:description" content="Subscribe to Microsoft Azure today for service updates, all in one place. Check out the new Cloud Platform roadmap to see our latest product plans." />
</head>
<body>
    <main><p>Short copy.</p></main>
</body>
</html>
HTML,
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $result = app(ArticlePageEnricher::class)->enrich('https://azure.example.com/updates?id=1');

        $this->assertNull($result->title);
        $this->assertNull($result->description);
        $this->assertNull($result->imageUrl);
    }
}