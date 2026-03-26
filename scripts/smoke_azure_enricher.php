<?php

declare(strict_types=1);

use App\Support\ArticlePageEnricher;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$service = app(ArticlePageEnricher::class);
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('enrichWithoutCache');
$method->setAccessible(true);

$result = $method->invoke(
    $service,
    'https://www.microsoft.com/en-us/sql-server/blog/2026/03/18/advancing-agentic-ai-with-microsoft-databases-across-a-unified-data-estate'
);

var_export($result);
echo PHP_EOL;
