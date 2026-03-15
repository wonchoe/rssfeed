<?php

namespace Tests\Unit;

use App\Domain\Source\Services\HttpSourceDiscoveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpSourceDiscoveryServiceTest extends TestCase
{
    public function test_discovery_probes_common_feed_endpoint_for_tsn(): void
    {
        Http::fake([
            'https://tsn.ua/' => Http::response(
                '<html><head><title>TSN</title></head><body><main>news portal</main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://tsn.ua/rss/full.rss' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>TSN Item</title>
      <link>https://tsn.ua/news/demo</link>
      <description>Demo</description>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
            ),
            '*' => Http::response('not found', 404),
        ]);

        $service = new HttpSourceDiscoveryService;
        $result = $service->discover('https://tsn.ua/');

        $this->assertNotNull($result->primaryCandidate);
        $this->assertSame('rss', $result->primaryCandidate->type);
        $this->assertSame('https://tsn.ua/rss/full.rss', $result->primaryCandidate->url);
    }

    public function test_discovery_probes_host_specific_bbc_feed(): void
    {
        Http::fake([
            'https://bbc.com/' => Http::response(
                '<html><head><title>BBC</title></head><body><main>news portal</main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://feeds.bbci.co.uk/news/rss.xml' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>BBC Item</title>
      <link>https://www.bbc.com/news/demo</link>
      <description>Demo</description>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
            ),
            '*' => Http::response('not found', 404),
        ]);

        $service = new HttpSourceDiscoveryService;
        $result = $service->discover('https://bbc.com/');

        $this->assertNotNull($result->primaryCandidate);
        $this->assertSame('rss', $result->primaryCandidate->type);
        $this->assertSame('https://feeds.bbci.co.uk/news/rss.xml', $result->primaryCandidate->url);
    }

    public function test_discovery_still_probes_feed_endpoints_when_homepage_request_fails(): void
    {
        Http::fake([
            'https://www.npr.org/' => Http::response('gateway timeout', 504),
            'https://feeds.npr.org/1001/rss.xml' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>NPR Item</title>
      <link>https://www.npr.org/2026/03/14/demo</link>
      <description>Demo</description>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
            ),
            '*' => Http::response('not found', 404),
        ]);

        $service = new HttpSourceDiscoveryService;
        $result = $service->discover('https://www.npr.org/');

        $this->assertNotNull($result->primaryCandidate);
        $this->assertSame('rss', $result->primaryCandidate->type);
        $this->assertSame('https://feeds.npr.org/1001/rss.xml', $result->primaryCandidate->url);
        $this->assertContains('Feed discovered via common endpoint probing.', $result->warnings);
    }

    public function test_discovery_supports_cross_domain_host_specific_feed_probe(): void
    {
        Http::fake([
            'https://www.axios.com/' => Http::response(
                '<html><head><title>Axios</title></head><body><main>news portal</main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://api.axios.com/feed' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Axios Item</title>
      <link>https://www.axios.com/2026/03/14/demo</link>
      <description>Demo</description>
    </item>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
            ),
            '*' => Http::response('not found', 404),
        ]);

        $service = new HttpSourceDiscoveryService;
        $result = $service->discover('https://www.axios.com/');

        $this->assertNotNull($result->primaryCandidate);
        $this->assertSame('rss', $result->primaryCandidate->type);
        $this->assertSame('https://api.axios.com/feed', $result->primaryCandidate->url);
    }
}
