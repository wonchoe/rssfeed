<?php

/**
 * Generate an HTML preview of Teams Adaptive Cards for all Teams subscriptions.
 * Run: CACHE_STORE=array php artisan tinker scripts/teams_preview.php
 * Or via SSH on prod: kubectl exec ... -- php artisan tinker scripts/teams_preview.php
 */

use App\Models\Subscription;
use App\Models\Article;
use App\Support\ArticlePageEnricher;
use Illuminate\Support\Facades\Http;

$enricher = new ArticlePageEnricher;
$ref = new ReflectionClass($enricher);
$extractMethod = $ref->getMethod('extractFromHtml');
$extractMethod->setAccessible(true);

$subs = Subscription::where('channel', 'teams')->with('source')->get();

if ($subs->isEmpty()) {
    echo "No Teams subscriptions found.\n";
    return;
}

$cards = [];

foreach ($subs as $sub) {
    $source = $sub->source;
    $articles = Article::where('source_id', $source->id)
        ->orderByDesc('id')
        ->take(2)
        ->get();

    foreach ($articles as $article) {
        $dbImage = $article->image_url;
        $dbSummary = $article->summary;

        // Run enrichment on the article page
        $enrichedImage = null;
        $enrichedDesc = null;
        $enrichSource = 'db';

        $needsImage = !$dbImage || trim($dbImage) === '';
        $needsSummary = !$dbSummary || trim($dbSummary) === '';

        if ($needsImage || $needsSummary) {
            try {
                $resp = Http::timeout(8)
                    ->withUserAgent('rss.cursor.style-ingestion-bot/1.0')
                    ->accept('text/html')
                    ->get($article->canonical_url);

                if ($resp->successful() && trim($resp->body()) !== '') {
                    $result = $extractMethod->invoke($enricher, $resp->body(), $article->canonical_url);
                    $enrichedImage = $result['image_url'];
                    $enrichedDesc = $result['description'];
                }
            } catch (Throwable $e) {
                // skip
            }
        }

        $finalImage = ($dbImage && trim($dbImage) !== '') ? $dbImage : $enrichedImage;
        $finalSummary = ($dbSummary && trim($dbSummary) !== '') ? $dbSummary : $enrichedDesc;

        // Check if DB image is an avatar
        $imageNote = '';
        if ($dbImage && $finalImage === $dbImage) {
            $lower = strtolower($dbImage);
            $avatarWords = ['avatar', 'profile', 'author', 'gravatar', 'face', 'width=64', 'height=64', 'crop'];
            foreach ($avatarWords as $w) {
                if (str_contains($lower, $w)) {
                    $imageNote = '⚠️ DB image looks like avatar — enricher would replace';
                    if ($enrichedImage) {
                        $finalImage = $enrichedImage;
                        $imageNote .= ' → replaced';
                    } else {
                        $imageNote .= ' → no enriched alt found, keeping DB';
                    }
                    break;
                }
            }
        }

        if ($finalImage !== $dbImage && $enrichedImage) {
            $enrichSource = 'enriched';
        }

        $cards[] = [
            'source_name' => $source->source_url,
            'source_id' => $source->id,
            'article_id' => $article->id,
            'title' => $article->title,
            'url' => $article->canonical_url,
            'db_image' => $dbImage ?: null,
            'enriched_image' => $enrichedImage,
            'final_image' => $finalImage,
            'image_note' => $imageNote,
            'db_summary' => $dbSummary ?: null,
            'enriched_summary' => $enrichedDesc,
            'final_summary' => $finalSummary,
            'image_source' => $enrichSource,
            'translate' => $sub->translate_enabled ? $sub->translate_language : null,
        ];
    }
}

// Build HTML
$html = <<<'HEAD'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Teams Cards Preview</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', system-ui, sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 24px; }
h1 { color: #7b68ee; margin-bottom: 24px; }
.source-group { margin-bottom: 32px; }
.source-label { font-size: 13px; color: #888; margin-bottom: 8px; padding-left: 4px; }
.card {
  background: #2a2a3e; border-radius: 8px; padding: 16px; margin-bottom: 16px;
  border-left: 4px solid #7b68ee; max-width: 640px;
}
.card-title { font-size: 16px; font-weight: 700; color: #fff; margin-bottom: 8px; }
.card-image { max-width: 100%; border-radius: 6px; margin-bottom: 10px; max-height: 300px; object-fit: cover; }
.card-body { font-size: 14px; color: #ccc; line-height: 1.5; margin-bottom: 12px; }
.card-action {
  display: inline-block; background: #7b68ee; color: #fff; text-decoration: none;
  padding: 6px 16px; border-radius: 4px; font-size: 13px; font-weight: 600;
}
.card-action:hover { background: #6a5acd; }
.meta { font-size: 11px; color: #666; margin-top: 10px; border-top: 1px solid #333; padding-top: 8px; }
.meta span { display: inline-block; margin-right: 14px; }
.tag-enriched { color: #4ec9b0; }
.tag-db { color: #9cdcfe; }
.tag-empty { color: #f44; }
.tag-warn { color: #fc0; }
.images-compare { display: flex; gap: 12px; margin: 8px 0; }
.images-compare div { flex: 1; }
.images-compare img { width: 100%; border-radius: 4px; max-height: 120px; object-fit: cover; }
.images-compare .label { font-size: 10px; color: #888; margin-bottom: 4px; }
</style>
</head>
<body>
<h1>🟣 Teams Adaptive Card Preview</h1>
<p style="color:#888;margin-bottom:24px;">Generated from prod DB + local enrichment. Shows what would be posted to Teams channels.</p>
HEAD;

$currentSource = null;
foreach ($cards as $c) {
    if ($currentSource !== $c['source_id']) {
        if ($currentSource !== null) $html .= "</div>\n";
        $html .= '<div class="source-group">';
        $html .= '<div class="source-label">📡 Source #' . $c['source_id'] . ' — ' . htmlspecialchars($c['source_name']) . '</div>';
        $currentSource = $c['source_id'];
    }

    $html .= '<div class="card">';
    $html .= '<div class="card-title">' . htmlspecialchars($c['title']) . '</div>';

    // Show image comparison if enriched differs from DB
    if ($c['db_image'] && $c['enriched_image'] && $c['db_image'] !== $c['enriched_image']) {
        $html .= '<div class="images-compare">';
        $html .= '<div><div class="label">DB image (current)</div><img src="' . htmlspecialchars($c['db_image']) . '"></div>';
        $html .= '<div><div class="label">Enriched (og:image)</div><img src="' . htmlspecialchars($c['enriched_image']) . '"></div>';
        $html .= '</div>';
    }

    if ($c['final_image']) {
        $html .= '<img class="card-image" src="' . htmlspecialchars($c['final_image']) . '">';
    }

    if ($c['final_summary']) {
        $html .= '<div class="card-body">' . htmlspecialchars($c['final_summary']) . '</div>';
    }

    $html .= '<a class="card-action" href="' . htmlspecialchars($c['url']) . '" target="_blank">Read Article</a>';

    // Meta
    $html .= '<div class="meta">';
    $html .= '<span>Article #' . $c['article_id'] . '</span>';

    $imgTag = match(true) {
        $c['final_image'] && $c['image_source'] === 'enriched' => '<span class="tag-enriched">img: enriched ✓</span>',
        $c['final_image'] !== null => '<span class="tag-db">img: from DB</span>',
        default => '<span class="tag-empty">img: none</span>',
    };
    $html .= $imgTag;

    $sumTag = match(true) {
        $c['final_summary'] && !$c['db_summary'] && $c['enriched_summary'] => '<span class="tag-enriched">summary: enriched ✓</span>',
        $c['final_summary'] !== null => '<span class="tag-db">summary: from DB</span>',
        default => '<span class="tag-empty">summary: none</span>',
    };
    $html .= $sumTag;

    if ($c['image_note']) {
        $html .= '<br><span class="tag-warn">' . htmlspecialchars($c['image_note']) . '</span>';
    }
    if ($c['translate']) {
        $html .= '<span>🌐 translate: ' . $c['translate'] . '</span>';
    }

    $html .= '</div></div>';
}

if ($currentSource !== null) $html .= "</div>\n";

$html .= "</body></html>";

$path = base_path('storage/teams_preview.html');
file_put_contents($path, $html);
echo "✅ Written " . count($cards) . " cards to: " . $path . "\n";
echo "Open: file://" . $path . "\n";
