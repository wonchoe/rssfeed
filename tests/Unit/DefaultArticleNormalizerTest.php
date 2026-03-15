<?php

namespace Tests\Unit;

use App\Data\Parsing\ParsedArticleData;
use App\Domain\Article\Services\DefaultArticleNormalizer;
use Tests\TestCase;

class DefaultArticleNormalizerTest extends TestCase
{
    public function test_normalizer_sanitizes_title_and_summary_html(): void
    {
        $normalizer = new DefaultArticleNormalizer;

        $normalized = $normalizer->normalize(new ParsedArticleData(
            url: 'https://example.com/post?id=1&utm_source=test',
            title: '<h1>Hello &amp; <em>world</em></h1>',
            externalId: 'abc-1',
            summary: '<p>Line <strong>one</strong>.</p> <p>Line two.</p>',
            imageUrl: null,
            publishedAt: '2026-03-05T17:00:00+00:00',
        ));

        $this->assertSame('Hello & world', $normalized->title);
        $this->assertSame('Line one. Line two.', $normalized->summary);
        $this->assertSame('2026-03-05T17:00:00+00:00', $normalized->publishedAt);
        $this->assertSame('https://example.com/post?id=1', $normalized->canonicalUrl);
    }
}
