<?php

namespace Tests\Unit;

use App\Domain\Parsing\Services\DeterministicFeedParserService;
use Tests\TestCase;

class DeterministicFeedParserServiceTest extends TestCase
{
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
}
