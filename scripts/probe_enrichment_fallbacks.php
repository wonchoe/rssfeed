<?php

declare(strict_types=1);

use App\Support\ArticlePageEnricher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sites = [
    'https://techcrunch.com/startups/',
    'https://www.microsoft.com/en-us/startups/blog/',
    'https://www.datadoghq.com/blog/',
    'https://stripe.com/blog',
    'https://netflixtechblog.com/',
    'https://engineering.fb.com/',
    'https://dropbox.tech/',
    'https://www.mongodb.com/company/blog',
    'https://www.notion.com/blog',
    'https://blog.discord.com/',
];

$enricher = app(ArticlePageEnricher::class);
$reflection = new ReflectionClass($enricher);
$extractMethod = $reflection->getMethod('extractFromHtml');
$extractMethod->setAccessible(true);

$rows = [];

foreach ($sites as $site) {
    $row = [
        'site' => $site,
        'article_url' => null,
        'title' => null,
        'summary' => null,
        'image_url' => null,
        'error' => null,
    ];

    try {
        $response = Http::timeout(20)
            ->withUserAgent((string) config('ingestion.fetch.user_agent'))
            ->accept('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
            ->get($site);

        if (! $response->successful()) {
            $row['error'] = 'site_http_'.$response->status();
            $rows[] = $row;
            continue;
        }

        $html = $response->body();
        $dom = new DOMDocument();
        if (! @$dom->loadHTML($html)) {
            $row['error'] = 'invalid_html';
            $rows[] = $row;
            continue;
        }

        $xpath = new DOMXPath($dom);
        $linkQueries = [
            '//article//a[@href][1]/@href',
            '//main//article//a[@href][1]/@href',
            '(//a[contains(@href, "/") and not(starts-with(@href, "#"))])[1]/@href',
        ];

        $articleUrl = null;

        foreach ($linkQueries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $candidate = trim((string) ($nodes->item(0)?->nodeValue ?? ''));
                if ($candidate !== '') {
                    $absolute = App\Support\UrlNormalizer::absolute($candidate, $site);
                    if (App\Support\UrlNormalizer::isValidHttpUrl($absolute) && $absolute !== $site) {
                        $articleUrl = $absolute;
                        break;
                    }
                }
            }
        }

        if ($articleUrl === null) {
            $row['error'] = 'no_article_link';
            $rows[] = $row;
            continue;
        }

        $articleResponse = Http::timeout(20)
            ->withUserAgent((string) config('ingestion.fetch.user_agent'))
            ->accept('text/html,application/xhtml+xml;q=0.9,*/*;q=0.8')
            ->get($articleUrl);

        if (! $articleResponse->successful()) {
            $row['article_url'] = $articleUrl;
            $row['error'] = 'article_http_'.$articleResponse->status();
            $rows[] = $row;
            continue;
        }

        /** @var array{title:?string,image_url:?string,description:?string} $result */
        $result = $extractMethod->invoke($enricher, $articleResponse->body(), $articleUrl);

        $row['article_url'] = $articleUrl;
        $row['title'] = $result['title'];
        $row['summary'] = $result['description'];
        $row['image_url'] = $result['image_url'];
    } catch (Throwable $exception) {
        $row['error'] = $exception->getMessage();
    }

    $rows[] = $row;
}

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
