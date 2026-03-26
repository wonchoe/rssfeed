<?php

/**
 * Generate an HTML audit preview for the latest article of each source.
 * Run on prod: kubectl exec ... -- php artisan tinker scripts/enrichment_audit_preview.php
 */

use App\Models\Source;
use App\Support\ArticlePageEnricher;

$enricher = app(ArticlePageEnricher::class);
$enricherReflection = new ReflectionClass($enricher);
$freshMethod = $enricherReflection->getMethod('enrichWithoutCache');
$freshMethod->setAccessible(true);

$sources = Source::query()
    ->with(['articles' => fn ($query) => $query->orderByDesc('published_at')->orderByDesc('id')->limit(1)])
    ->orderBy('id')
    ->get();

$rows = [];

foreach ($sources as $source) {
    $article = $source->articles->first();

    if ($article === null || ! is_string($article->canonical_url) || trim($article->canonical_url) === '') {
        $rows[] = [
            'source_id' => $source->id,
            'source_url' => $source->source_url,
            'source_type' => $source->source_type,
            'status' => $source->status,
            'article_id' => null,
            'article_url' => null,
            'db_title' => null,
            'db_summary' => null,
            'db_image' => null,
            'fresh_title' => null,
            'fresh_summary' => null,
            'fresh_image' => null,
            'error' => 'No article available for audit.',
        ];

        continue;
    }

    try {
        /** @var array{title:?string,image_url:?string,description:?string} $fresh */
        $fresh = $freshMethod->invoke($enricher, $article->canonical_url);
        $error = null;
    } catch (Throwable $exception) {
        $fresh = ['title' => null, 'image_url' => null, 'description' => null];
        $error = $exception->getMessage();
    }

    $rows[] = [
        'source_id' => $source->id,
        'source_url' => $source->source_url,
        'source_type' => $source->source_type,
        'status' => $source->status,
        'article_id' => $article->id,
        'article_url' => $article->canonical_url,
        'db_title' => $article->title,
        'db_summary' => $article->summary,
        'db_image' => $article->image_url,
        'fresh_title' => $fresh['title'],
        'fresh_summary' => $fresh['description'],
        'fresh_image' => $fresh['image_url'],
        'error' => $error,
    ];
}

$escape = static fn (?string $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$truncate = static fn (?string $value, int $limit): string => mb_strlen((string) $value) > $limit
    ? mb_substr((string) $value, 0, $limit - 1).'...'
    : (string) ($value ?? '');

$html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Enrichment Audit Preview</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: Georgia, "Times New Roman", serif; background: #f4f0e8; color: #1b1b1b; }
.page { max-width: 1500px; margin: 0 auto; padding: 32px 24px 80px; }
h1 { margin: 0 0 10px; font-size: 34px; }
.intro { margin: 0 0 28px; color: #5b564d; font-size: 15px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 18px; }
.card { background: #fffdf8; border: 1px solid #d8cfbf; border-radius: 14px; padding: 18px; box-shadow: 0 10px 30px rgba(55, 42, 15, 0.06); }
.heading { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; margin-bottom: 10px; }
.heading strong { font-size: 18px; }
.muted { color: #776f63; font-size: 12px; }
.article-link { display: block; font-size: 13px; color: #005a7a; text-decoration: none; margin-bottom: 12px; word-break: break-all; }
.status-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.tag { border-radius: 999px; padding: 3px 9px; font-size: 11px; background: #eee4d1; color: #5f4b24; }
.tag-ok { background: #dff3e4; color: #215732; }
.tag-warn { background: #fce6cf; color: #8a4b08; }
.tag-bad { background: #f9d9d9; color: #8f1f1f; }
.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.panel { border: 1px solid #e5dccd; border-radius: 10px; padding: 12px; background: #fff; }
.panel h3 { margin: 0 0 8px; font-size: 13px; color: #655f56; text-transform: uppercase; letter-spacing: .04em; }
.field { margin-bottom: 12px; }
.field:last-child { margin-bottom: 0; }
.field-label { font-size: 11px; color: #7a7368; text-transform: uppercase; margin-bottom: 4px; }
.field-value { font-size: 14px; line-height: 1.45; }
.field-value.empty { color: #9c9488; font-style: italic; }
.image { width: 100%; max-height: 190px; object-fit: cover; border-radius: 8px; border: 1px solid #e7decf; background: #f7f2ea; }
.error { margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: #fff0ef; color: #8b2e2e; font-size: 13px; }
@media (max-width: 980px) { .cols { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="page">
<h1>Enrichment Audit Preview</h1>
<p class="intro">Latest article per source from production DB, compared against a fresh uncached article-page enrichment run. No deliveries triggered.</p>
<div class="grid">
HTML;

foreach ($rows as $row) {
    $titleChanged = ($row['db_title'] ?? null) !== ($row['fresh_title'] ?? null) && ($row['fresh_title'] ?? null) !== null;
    $summaryChanged = ($row['db_summary'] ?? null) !== ($row['fresh_summary'] ?? null) && ($row['fresh_summary'] ?? null) !== null;
    $imageChanged = ($row['db_image'] ?? null) !== ($row['fresh_image'] ?? null) && ($row['fresh_image'] ?? null) !== null;

    $html .= '<div class="card">';
    $html .= '<div class="heading"><strong>Source #'.$row['source_id'].'</strong><span class="muted">'.$escape((string) $row['source_type']).' / '.$escape((string) $row['status']).'</span></div>';
    $html .= '<div class="muted" style="margin-bottom:8px;">'.$escape($row['source_url']).'</div>';

    if ($row['article_url'] !== null) {
        $html .= '<a class="article-link" href="'.$escape($row['article_url']).'" target="_blank">'.$escape($row['article_url']).'</a>';
    }

    $html .= '<div class="status-row">';
    $html .= '<span class="tag">article #'.($row['article_id'] ?? 'n/a').'</span>';
    $html .= '<span class="tag '.($titleChanged ? 'tag-warn' : 'tag-ok').'">title '.($titleChanged ? 'changed' : 'stable').'</span>';
    $html .= '<span class="tag '.($summaryChanged ? 'tag-warn' : 'tag-ok').'">summary '.($summaryChanged ? 'changed' : 'stable').'</span>';
    $html .= '<span class="tag '.($imageChanged ? 'tag-warn' : (($row['fresh_image'] ?? null) === null ? 'tag-bad' : 'tag-ok')).'">image '.($imageChanged ? 'changed' : (($row['fresh_image'] ?? null) === null ? 'missing' : 'stable')).'</span>';
    $html .= '</div>';

    $html .= '<div class="cols">';

    foreach ([
        'DB' => ['title' => $row['db_title'], 'summary' => $row['db_summary'], 'image' => $row['db_image']],
        'Fresh OG' => ['title' => $row['fresh_title'], 'summary' => $row['fresh_summary'], 'image' => $row['fresh_image']],
    ] as $label => $values) {
        $html .= '<div class="panel">';
        $html .= '<h3>'.$escape($label).'</h3>';

        $html .= '<div class="field"><div class="field-label">Title</div><div class="field-value'.(($values['title'] ?? null) === null || trim((string) $values['title']) === '' ? ' empty' : '').'">'.$escape($truncate($values['title'], 220) !== '' ? $truncate($values['title'], 220) : 'none').'</div></div>';
        $html .= '<div class="field"><div class="field-label">Summary</div><div class="field-value'.(($values['summary'] ?? null) === null || trim((string) $values['summary']) === '' ? ' empty' : '').'">'.$escape($truncate($values['summary'], 420) !== '' ? $truncate($values['summary'], 420) : 'none').'</div></div>';
        $html .= '<div class="field"><div class="field-label">Image</div>';

        if (($values['image'] ?? null) !== null && trim((string) $values['image']) !== '') {
            $html .= '<img class="image" src="'.$escape($values['image']).'" alt="preview image">';
            $html .= '<div class="field-value" style="margin-top:6px; word-break:break-all;">'.$escape($values['image']).'</div>';
        } else {
            $html .= '<div class="field-value empty">none</div>';
        }

        $html .= '</div></div>';
    }

    $html .= '</div>';

    if ($row['error'] !== null) {
        $html .= '<div class="error">'.$escape($row['error']).'</div>';
    }

    $html .= '</div>';
}

$html .= '</div></div></body></html>';

$path = base_path('storage/enrichment_audit_preview.html');
file_put_contents($path, $html);

echo 'Written '.count($rows).' audit cards to '.$path.PHP_EOL;