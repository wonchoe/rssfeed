<?php

namespace Tests\Unit;

use App\Domain\Parsing\Services\DeterministicFeedParserService;
use App\Models\Source;
use App\Support\ParserSchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeterministicFeedParserServiceTest extends TestCase
{
  use RefreshDatabase;

    public function test_rss_summary_is_cleaned_and_image_is_extracted(): void
    {
        $payload = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <item>
      <title>Demo Item</title>
      <link>https://example.com/articles/demo</link>
      <description><![CDATA[<p>Hello <strong>world</strong></p>]]></description>
      <content:encoded><![CDATA[<div><img src="/images/demo.jpg" /><p>Body</p></div>]]></content:encoded>
      <pubDate>Sat, 14 Mar 2026 10:00:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;

        $service = new DeterministicFeedParserService;
        $items = $service->parse($payload, 'https://example.com/feed.xml', 'rss');

        $this->assertCount(1, $items);
        $this->assertSame('Hello world', $items[0]->summary);
        $this->assertSame('https://example.com/images/demo.jpg', $items[0]->imageUrl);
        $this->assertSame('2026-03-14T10:00:00+00:00', $items[0]->publishedAt);
    }

    public function test_html_schema_parser_filters_avatar_images_and_short_noise_titles(): void
    {
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://example.com/news'),
            'source_url' => 'https://example.com/news',
            'canonical_url_hash' => hash('sha256', 'https://example.com/news'),
            'canonical_url' => 'https://example.com/news',
            'source_type' => 'html',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 100,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
        ]);

        app(ParserSchemaRegistry::class)->activateCustomSchema(
            source: $source,
            strategyType: 'deterministic_html_schema',
            schemaPayload: [
                'article_xpath' => '//*[contains(concat(" ", normalize-space(@class), " "), " card ")][.//a[@href] and (.//h2 or .//h3)]',
                'title_xpath' => './/h2//a[@href][1] | .//h2[1] | .//a[@href][1]',
                'link_xpath' => './/h2//a[@href][1]/@href | .//a[@href][1]/@href',
                'summary_xpath' => './/p[1]',
                'image_xpath' => './/img[1]/@src',
                'date_xpath' => './/time/@datetime | .//time',
            ],
            confidence: 0.9,
            createdBy: 'test',
        );

        $payload = <<<'HTML'
<html>
  <body>
    <main>
      <div class="card">
        <h2><a href="https://example.com/news/real-story">A proper article title worth parsing</a></h2>
        <p>This summary is long enough to be considered useful for article extraction.</p>
        <img src="https://example.com/images/avatar-author.png" />
        <time datetime="2026-03-25T12:00:00Z"></time>
      </div>
      <div class="card">
        <h2><a href="https://example.com/news/cta">Read more</a></h2>
        <p>Short</p>
      </div>
    </main>
  </body>
</html>
HTML;

        $service = new DeterministicFeedParserService;
        $items = $service->parse($payload, 'https://example.com/news', 'html');

        $this->assertCount(1, $items);
        $this->assertSame('A proper article title worth parsing', $items[0]->title);
        $this->assertSame('This summary is long enough to be considered useful for article extraction.', $items[0]->summary);
        $this->assertNull($items[0]->imageUrl);
    }
}
