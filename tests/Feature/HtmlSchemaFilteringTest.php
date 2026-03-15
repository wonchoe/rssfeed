<?php

namespace Tests\Feature;

use App\Domain\Parsing\Services\DeterministicFeedParserService;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HtmlSchemaFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_schema_parser_filters_navigation_links_from_candidates(): void
    {
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://tsn.ua/'),
            'source_url' => 'https://tsn.ua/',
            'canonical_url_hash' => hash('sha256', 'https://tsn.ua/'),
            'canonical_url' => 'https://tsn.ua/',
            'source_type' => 'html',
            'status' => 'active',
            'usage_state' => 'inactive',
            'health_score' => 60,
            'health_state' => 'unknown',
            'polling_interval_minutes' => 30,
        ]);

        $source->parserSchemas()->create([
            'version' => 1,
            'strategy_type' => 'ai_xpath_schema',
            'schema_payload' => [
                'article_xpath' => '//main//*[self::article or self::div][.//a[@href]]',
                'title_xpath' => './/h2|.//a[1]',
                'link_xpath' => './/a[@href][1]/@href',
                'summary_xpath' => './/p[1]',
                'image_xpath' => './/img[1]/@src',
                'date_xpath' => './/time/@datetime|.//time',
            ],
            'created_by' => 'test',
            'is_active' => true,
            'is_shadow' => false,
        ]);

        $payload = <<<'HTML'
<!doctype html>
<html>
  <body>
    <main>
      <div class="nav-card">
        <a href="/ukrayina">Україна</a>
      </div>
      <article>
        <h2>Важлива новина</h2>
        <a href="/news/2026/03/14/important-story">Читати</a>
        <p>Короткий опис новини з деталями.</p>
        <time datetime="2026-03-14T10:00:00+00:00">14 березня</time>
      </article>
      <div class="nav-card">
        <a href="/politika">Політика</a>
      </div>
    </main>
  </body>
</html>
HTML;

        $service = app(DeterministicFeedParserService::class);
        $items = $service->parse($payload, 'https://tsn.ua/', 'html');

        $urls = array_map(static fn ($item): string => $item->url, $items);

        $this->assertContains('https://tsn.ua/news/2026/03/14/important-story', $urls);
        $this->assertNotContains('https://tsn.ua/ukrayina', $urls);
        $this->assertNotContains('https://tsn.ua/politika', $urls);
    }
}
