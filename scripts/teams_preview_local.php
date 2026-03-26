<?php

/**
 * Generate Teams preview HTML from exported prod JSON + live enrichment.
 * Run: php scripts/teams_preview_local.php
 */

$jsonPath = '/tmp/teams_articles.json';
if (!file_exists($jsonPath)) {
    echo "Missing $jsonPath — export from prod first.\n";
    exit(1);
}

$articles = json_decode(file_get_contents($jsonPath), true);
if (!$articles) {
    echo "Empty or invalid JSON.\n";
    exit(1);
}

echo "Processing " . count($articles) . " articles...\n";

$avatarPatterns = ['avatar', 'profile', 'author', 'gravatar', 'face', 'width=64', 'height=64', 'crop', 'zoom=0.5'];

function isAvatarUrl(string $url): bool {
    global $avatarPatterns;
    $lower = strtolower($url);
    foreach ($avatarPatterns as $p) {
        if (str_contains($lower, $p)) return true;
    }
    return false;
}

function isActualImage(string $url): bool {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'HEAD',
            'timeout' => 4,
            'user_agent' => 'rss.cursor.style-ingestion-bot/1.0',
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $headers = @get_headers($url, true, $ctx);
    if (!$headers) return true; // network error — give benefit of doubt
    $ct = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
    if (is_array($ct)) $ct = end($ct);
    return str_starts_with(strtolower($ct), 'image/');
}

function extractFromHtml(string $html, string $baseUrl): array {
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xp = new DOMXPath($dom);

    // Image
    $imgQueries = [
        '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
        '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image"]/@content',
        '//article//img[@src][1]/@src',
    ];
    $image = null;
    foreach ($imgQueries as $q) {
        $nodes = @$xp->query($q);
        if ($nodes && $nodes->length > 0) {
            $val = trim($nodes->item(0)->nodeValue ?? '');
            if ($val !== '' && !str_starts_with(strtolower($val), 'data:')) {
                if (!isAvatarUrl($val) && isActualImage($val)) {
                    $image = $val;
                    break;
                }
            }
        }
    }

    // Description
    $descQueries = [
        '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content',
        '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content',
    ];
    $desc = null;
    foreach ($descQueries as $q) {
        $nodes = @$xp->query($q);
        if ($nodes && $nodes->length > 0) {
            $val = trim(strip_tags(html_entity_decode($nodes->item(0)->nodeValue ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $val = preg_replace('/\s+/u', ' ', $val);
            if (mb_strlen($val) >= 20) {
                $desc = mb_strlen($val) > 420 ? mb_substr($val, 0, 417) . '...' : $val;
                break;
            }
        }
    }

    return ['image' => $image, 'description' => $desc];
}

$cards = [];
foreach ($articles as $a) {
    echo "  → " . mb_substr($a['title'], 0, 60) . "... ";

    $dbImage = $a['db_image'];
    $dbSummary = $a['db_summary'];
    $enrichedImage = null;
    $enrichedDesc = null;

    $needsImage = !$dbImage || trim($dbImage) === '' || isAvatarUrl($dbImage);
    $needsSummary = !$dbSummary || trim($dbSummary) === '';

    if ($needsImage || $needsSummary) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 8,
                'user_agent' => 'rss.cursor.style-ingestion-bot/1.0',
                'header' => "Accept: text/html\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($a['url'], false, $ctx);
        if ($body) {
            $result = extractFromHtml($body, $a['url']);
            $enrichedImage = $result['image'];
            $enrichedDesc = $result['description'];
        }
    }

    // Validate DB image is actually an image (not video/other)
    $dbImageValid = $dbImage && trim($dbImage) !== '' && !isAvatarUrl($dbImage) && isActualImage($dbImage);

    $finalImage = $dbImageValid ? $dbImage : ($enrichedImage ?? null);
    $finalSummary = ($dbSummary && trim($dbSummary) !== '') ? $dbSummary : $enrichedDesc;

    $imageNote = '';
    if ($dbImage && !$dbImageValid && trim($dbImage) !== '') {
        if (isAvatarUrl($dbImage)) {
            $imageNote = '⚠️ DB image is avatar/small';
        } else {
            $imageNote = '⚠️ DB image failed HEAD check (not image/)';
        }
        $imageNote .= $enrichedImage ? ' → replaced by og:image' : ' → no replacement found';
    }

    $cards[] = [
        'source_url' => $a['source_url'],
        'source_id' => $a['source_id'],
        'article_id' => $a['article_id'],
        'title' => $a['title'],
        'url' => $a['url'],
        'db_image' => $dbImage,
        'enriched_image' => $enrichedImage,
        'final_image' => $finalImage,
        'image_note' => $imageNote,
        'db_summary' => $dbSummary,
        'enriched_summary' => $enrichedDesc,
        'final_summary' => $finalSummary,
    ];
    echo "OK\n";
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
h1 { color: #7b68ee; margin-bottom: 8px; }
.subtitle { color: #888; margin-bottom: 24px; font-size: 13px; }
.source-group { margin-bottom: 32px; }
.source-label { font-size: 13px; color: #888; margin-bottom: 8px; padding-left: 4px; border-bottom: 1px solid #333; padding-bottom: 4px; }
.card {
  background: #2a2a3e; border-radius: 8px; padding: 16px; margin-bottom: 16px;
  border-left: 4px solid #7b68ee; max-width: 640px;
}
.card-title { font-size: 16px; font-weight: 700; color: #fff; margin-bottom: 10px; }
.card-image { max-width: 100%; border-radius: 6px; margin-bottom: 10px; max-height: 300px; object-fit: cover; }
.card-body { font-size: 14px; color: #ccc; line-height: 1.5; margin-bottom: 12px; }
.card-action {
  display: inline-block; background: #7b68ee; color: #fff; text-decoration: none;
  padding: 7px 18px; border-radius: 4px; font-size: 13px; font-weight: 600;
}
.card-action:hover { background: #6a5acd; }
.meta { font-size: 11px; color: #666; margin-top: 12px; border-top: 1px solid #333; padding-top: 8px; line-height: 1.8; }
.tag-enriched { color: #4ec9b0; }
.tag-db { color: #9cdcfe; }
.tag-empty { color: #f44; }
.tag-warn { color: #fc0; }
.compare { display: flex; gap: 12px; margin: 8px 0; }
.compare div { flex: 1; }
.compare img { width: 100%; border-radius: 4px; max-height: 140px; object-fit: cover; }
.compare .label { font-size: 10px; color: #888; margin-bottom: 4px; }
.no-image { background: #1e1e30; border: 1px dashed #444; border-radius: 6px; padding: 20px; text-align: center; color: #555; margin-bottom: 10px; font-size: 12px; }
</style>
</head>
<body>
<h1>🟣 Teams Adaptive Card Preview</h1>
<p class="subtitle">Prod DB data + local enrichment. Shows what would actually be posted to Teams channels after the enrichment pipeline runs.</p>
HEAD;

$currentSource = null;
foreach ($cards as $c) {
    if ($currentSource !== $c['source_id']) {
        if ($currentSource !== null) $html .= "</div>\n";
        $html .= '<div class="source-group">';
        $html .= '<div class="source-label">📡 Source #' . $c['source_id'] . ' — ' . htmlspecialchars($c['source_url']) . '</div>';
        $currentSource = $c['source_id'];
    }

    $html .= '<div class="card">';
    $html .= '<div class="card-title">' . htmlspecialchars($c['title']) . '</div>';

    // Image comparison if DB had avatar and enriched has replacement
    if ($c['db_image'] && $c['enriched_image'] && $c['db_image'] !== $c['enriched_image'] && isAvatarUrl($c['db_image'])) {
        $html .= '<div class="compare">';
        $html .= '<div><div class="label">❌ DB image (avatar)</div><img src="' . htmlspecialchars($c['db_image']) . '"></div>';
        $html .= '<div><div class="label">✅ Enriched (og:image)</div><img src="' . htmlspecialchars($c['enriched_image']) . '"></div>';
        $html .= '</div>';
    }

    if ($c['final_image']) {
        $html .= '<img class="card-image" src="' . htmlspecialchars($c['final_image']) . '">';
    } else {
        $html .= '<div class="no-image">No image available</div>';
    }

    if ($c['final_summary']) {
        $html .= '<div class="card-body">' . htmlspecialchars($c['final_summary']) . '</div>';
    }

    $html .= '<a class="card-action" href="' . htmlspecialchars($c['url']) . '" target="_blank">Read Article</a>';

    // Meta info
    $html .= '<div class="meta">';
    $html .= '<span>Article #' . $c['article_id'] . '</span> ';

    if ($c['final_image']) {
        if ($c['enriched_image'] && $c['final_image'] === $c['enriched_image'] && $c['final_image'] !== $c['db_image']) {
            $html .= '<span class="tag-enriched">● img: enriched</span> ';
        } else {
            $html .= '<span class="tag-db">● img: from DB</span> ';
        }
    } else {
        $html .= '<span class="tag-empty">● img: none</span> ';
    }

    if ($c['final_summary']) {
        if (!$c['db_summary'] && $c['enriched_summary']) {
            $html .= '<span class="tag-enriched">● summary: enriched</span>';
        } else {
            $html .= '<span class="tag-db">● summary: from DB</span>';
        }
    } else {
        $html .= '<span class="tag-empty">● summary: none</span>';
    }

    if ($c['image_note']) {
        $html .= '<br><span class="tag-warn">' . htmlspecialchars($c['image_note']) . '</span>';
    }

    $html .= '</div></div>';
}

if ($currentSource !== null) $html .= "</div>\n";

$html .= '<div style="margin-top:32px;color:#555;font-size:11px;">Generated: ' . date('Y-m-d H:i:s') . ' | Articles: ' . count($cards) . '</div>';
$html .= "</body></html>";

$outPath = __DIR__ . '/../storage/teams_preview.html';
file_put_contents($outPath, $html);
echo "\n✅ Written " . count($cards) . " cards to: $outPath\n";
