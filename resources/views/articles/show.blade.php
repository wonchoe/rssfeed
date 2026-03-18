<!DOCTYPE html>
<html lang="{{ $translatedArticle->language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $translatedArticle->title }} · rss.cursor.style</title>
    <meta name="description" content="{{ Str::limit(strip_tags($translatedArticle->content_html), 200) }}">
    @if ($translatedArticle->image_url)
        <meta property="og:image" content="{{ $translatedArticle->image_url }}">
    @endif
    <meta property="og:title" content="{{ $translatedArticle->title }}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ $translatedArticle->publicUrl() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0f1e;
            --bg-soft: #0f1729;
            --surface: #141d32;
            --surface-2: #1a2740;
            --text: #e5ecff;
            --text-secondary: #b0bfdb;
            --muted: #7889a8;
            --brand: #5da8ff;
            --brand-soft: rgba(93, 168, 255, 0.12);
            --accent: #ef8f4e;
            --line: rgba(255, 255, 255, 0.08);
            --line-strong: rgba(255, 255, 255, 0.14);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Inter", "Manrope", -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        body::before {
            content: "";
            position: fixed;
            top: -20%;
            left: -15%;
            width: min(60vw, 700px);
            height: min(60vw, 700px);
            border-radius: 999px;
            background: radial-gradient(circle, rgba(93, 168, 255, 0.10), transparent 70%);
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: "";
            position: fixed;
            bottom: -25%;
            right: -10%;
            width: min(50vw, 600px);
            height: min(50vw, 600px);
            border-radius: 999px;
            background: radial-gradient(circle, rgba(239, 143, 78, 0.12), transparent 70%);
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
        }

        body > * { position: relative; z-index: 1; }

        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Header bar ────────────────────────── */
        .top-bar {
            border-bottom: 1px solid var(--line);
            background: rgba(14, 20, 38, 0.85);
            backdrop-filter: blur(16px);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .top-bar-brand {
            font-family: "Manrope", sans-serif;
            font-weight: 800;
            font-size: 16px;
            color: var(--text);
            letter-spacing: -0.02em;
        }

        .top-bar-links {
            display: flex;
            gap: 16px;
            align-items: center;
            font-size: 13px;
        }

        .top-bar-links a {
            color: var(--muted);
            transition: color .15s;
        }

        .top-bar-links a:hover {
            color: var(--text);
            text-decoration: none;
        }

        .badge-lang {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--brand-soft);
            border: 1px solid rgba(93, 168, 255, 0.2);
            color: var(--brand);
            border-radius: 999px;
            padding: 3px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        /* ── Hero / cover image ────────────────── */
        .article-hero {
            max-width: 820px;
            margin: 0 auto;
            padding: 32px 24px 0;
        }

        .article-hero img {
            width: 100%;
            max-height: 420px;
            object-fit: cover;
            border-radius: 16px;
            border: 1px solid var(--line);
        }

        /* ── Article header ────────────────────── */
        .article-header {
            max-width: 720px;
            margin: 0 auto;
            padding: 28px 24px 0;
        }

        .article-header h1 {
            font-family: "Space Grotesk", sans-serif;
            font-size: clamp(26px, 4vw, 38px);
            font-weight: 700;
            line-height: 1.25;
            letter-spacing: -0.03em;
            margin-bottom: 16px;
        }

        .article-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            font-size: 13px;
            color: var(--muted);
            padding-bottom: 20px;
            border-bottom: 1px solid var(--line);
        }

        .article-meta a {
            color: var(--text-secondary);
        }

        /* ── Article body ──────────────────────── */
        .article-body {
            max-width: 720px;
            margin: 0 auto;
            padding: 28px 24px 48px;
            font-size: 16.5px;
            line-height: 1.78;
            color: var(--text-secondary);
        }

        .article-body h1,
        .article-body h2,
        .article-body h3,
        .article-body h4,
        .article-body h5,
        .article-body h6 {
            font-family: "Space Grotesk", sans-serif;
            color: var(--text);
            margin: 1.6em 0 0.6em;
            line-height: 1.3;
            letter-spacing: -0.02em;
        }

        .article-body h2 { font-size: 1.5em; }
        .article-body h3 { font-size: 1.25em; }
        .article-body h4 { font-size: 1.1em; }

        .article-body p {
            margin: 0 0 1.2em;
        }

        .article-body img {
            max-width: 100%;
            height: auto;
            border-radius: 12px;
            border: 1px solid var(--line);
            margin: 1.2em 0;
            display: block;
        }

        .article-body iframe {
            max-width: 100%;
            border-radius: 12px;
            border: 1px solid var(--line);
            margin: 1.2em 0;
            display: block;
            aspect-ratio: 16 / 9;
            width: 100%;
            height: auto;
        }

        .article-body video {
            max-width: 100%;
            border-radius: 12px;
            margin: 1.2em 0;
        }

        .article-body a {
            color: var(--brand);
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .article-body a:hover {
            color: #78bcff;
        }

        .article-body ul,
        .article-body ol {
            padding-left: 1.6em;
            margin: 0 0 1.2em;
        }

        .article-body li {
            margin-bottom: 0.4em;
        }

        .article-body blockquote {
            border-left: 3px solid var(--brand);
            padding: 12px 20px;
            margin: 1.2em 0;
            background: rgba(93, 168, 255, 0.06);
            border-radius: 0 10px 10px 0;
            color: var(--text);
            font-style: italic;
        }

        .article-body pre {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px 20px;
            overflow-x: auto;
            margin: 1.2em 0;
            font-size: 14px;
            line-height: 1.6;
        }

        .article-body code {
            font-family: "SF Mono", "Fira Code", monospace;
            font-size: 0.9em;
            background: var(--surface);
            padding: 2px 6px;
            border-radius: 5px;
            border: 1px solid var(--line);
        }

        .article-body pre code {
            background: none;
            border: none;
            padding: 0;
        }

        .article-body table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.2em 0;
            font-size: 14px;
        }

        .article-body th,
        .article-body td {
            border: 1px solid var(--line);
            padding: 10px 14px;
            text-align: left;
        }

        .article-body th {
            background: var(--surface);
            color: var(--text);
            font-weight: 600;
        }

        .article-body figure {
            margin: 1.4em 0;
        }

        .article-body figcaption {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
            margin-top: 8px;
        }

        .article-body hr {
            border: none;
            border-top: 1px solid var(--line);
            margin: 2em 0;
        }

        /* ── Footer ────────────────────────────── */
        .article-footer {
            max-width: 720px;
            margin: 0 auto;
            padding: 0 24px 48px;
        }

        .article-footer-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--surface);
            padding: 20px 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }

        .article-footer-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .original-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
            background: rgba(93, 168, 255, 0.12);
            border: 1px solid rgba(93, 168, 255, 0.2);
            transition: all .15s;
        }

        .original-link:hover {
            background: rgba(93, 168, 255, 0.2);
            text-decoration: none;
        }

        /* ── Responsive ────────────────────────── */
        @media (max-width: 640px) {
            .top-bar { padding: 12px 16px; }
            .article-hero { padding: 20px 16px 0; }
            .article-header { padding: 20px 16px 0; }
            .article-body { padding: 20px 16px 40px; font-size: 15.5px; }
            .article-footer { padding: 0 16px 40px; }
            .article-hero img { border-radius: 12px; max-height: 280px; }
        }
    </style>
</head>
<body>
    <header class="top-bar">
        <a href="{{ url('/') }}" class="top-bar-brand">rss.cursor.style</a>
        <div class="top-bar-links">
            <span class="badge-lang">{{ strtoupper($translatedArticle->language) }}</span>
            <a href="{{ $translatedArticle->original_url }}" target="_blank" rel="noopener noreferrer">Original</a>
        </div>
    </header>

    @if ($translatedArticle->image_url)
        <div class="article-hero">
            <img src="{{ $translatedArticle->image_url }}" alt="{{ $translatedArticle->title }}" loading="eager" referrerpolicy="no-referrer">
        </div>
    @endif

    <header class="article-header">
        <h1>{{ $translatedArticle->title }}</h1>
        <div class="article-meta">
            @if ($translatedArticle->source_name)
                <span>Source: <a href="{{ $translatedArticle->source_url ?? $translatedArticle->original_url }}" target="_blank" rel="noopener noreferrer">{{ $translatedArticle->source_name }}</a></span>
            @endif
            @if ($translatedArticle->translated_at)
                <span>Translated {{ $translatedArticle->translated_at->diffForHumans() }}</span>
            @endif
            @if ($translatedArticle->article?->published_at)
                <span>Published {{ $translatedArticle->article->published_at->format('M d, Y') }}</span>
            @endif
        </div>
    </header>

    <article class="article-body">
        {!! $translatedArticle->content_html !!}
    </article>

    <footer class="article-footer">
        <div class="article-footer-card">
            <p>Translated by <strong>rss.cursor.style</strong> via AI. Content may differ from the original.</p>
            <a href="{{ $translatedArticle->original_url }}" class="original-link" target="_blank" rel="noopener noreferrer">
                Read Original →
            </a>
        </div>
    </footer>
</body>
</html>
