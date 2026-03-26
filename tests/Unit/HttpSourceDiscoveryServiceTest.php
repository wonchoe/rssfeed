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

    public function test_discovery_prefers_detected_html_listing_pattern_before_feed_fallback(): void
    {
        Http::fake([
            'https://github.blog/changelog' => Http::response(
                <<<'HTML'
<html>
  <head>
    <link rel="alternate" type="application/rss+xml" href="https://github.blog/changelog/feed/" />
  </head>
  <body>
    <main>
      <article class="news-card">
        <time datetime="2026-03-25">Mar.25</time>
        <h2><a class="article-title" href="https://github.blog/changelog/2026-03-25-first-item">First changelog item title</a></h2>
        <p>This is a useful summary for the first changelog item with enough text.</p>
        <img src="https://github.blog/images/first-item.jpg" />
      </article>
      <article class="news-card">
        <time datetime="2026-03-24">Mar.24</time>
        <h2><a class="article-title" href="https://github.blog/changelog/2026-03-24-second-item">Second changelog item title</a></h2>
        <p>This is a useful summary for the second changelog item with enough text.</p>
      </article>
      <article class="news-card">
        <time datetime="2026-03-23">Mar.23</time>
        <h2><a class="article-title" href="https://github.blog/changelog/2026-03-23-third-item">Third changelog item title</a></h2>
        <p>This is a useful summary for the third changelog item with enough text.</p>
      </article>
    </main>
  </body>
</html>
HTML,
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            '*' => Http::response('not found', 404),
        ]);

        $service = new HttpSourceDiscoveryService;
        $result = $service->discover('https://github.blog/changelog');

        $this->assertNotNull($result->primaryCandidate);
        $this->assertSame('html', $result->primaryCandidate->type);
        $this->assertIsArray($result->primaryCandidate->meta['schema_payload'] ?? null);
        $this->assertContains('Deterministic HTML listing pattern detected.', $result->warnings);
        $this->assertTrue(collect($result->candidates)->contains(fn ($candidate): bool => $candidate->url === 'https://github.blog/changelog/feed' && $candidate->type === 'rss'));
    }

    public function test_discovery_uses_explicit_rss_action_link_for_client_rendered_listing_pages(): void
    {
        Http::fake([
            'https://azure.microsoft.com/en-us/updates' => Http::response(
                <<<'HTML'
<html>
  <head><title>Azure updates</title></head>
  <body>
    <main>
      <h1>Azure Updates</h1>
      <a href="https://www.microsoft.com/releasecommunications/api/v2/azure/rss" aria-label="Subscribe via RSS">Subscribe via RSS</a>
      <div api="https://www.microsoft.com/releasecommunications/api/v2/azure">
        <ul id="accordion-container"></ul>
      </div>
    </main>
  </body>
</html>
HTML,
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://www.microsoft.com/releasecommunications/api/v2/azure/rss' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Azure Item</title>
      <link>https://azure.microsoft.com/en-us/updates/demo</link>
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
        $result = $service->discover('https://azure.microsoft.com/en-us/updates');

        $this->assertNotNull($result->primaryCandidate);
        $this->assertSame('rss', $result->primaryCandidate->type);
        $this->assertSame('https://www.microsoft.com/releasecommunications/api/v2/azure/rss', $result->primaryCandidate->url);
        $this->assertContains('Feed discovered via explicit RSS/feed action link in HTML.', $result->warnings);
    }
}
