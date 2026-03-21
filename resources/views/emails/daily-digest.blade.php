<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $sourceName }} — Daily Digest</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f5f5f5; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1a1a2e; color: #fff; padding: 24px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 20px; font-weight: 600; }
        .header p { margin: 8px 0 0; font-size: 14px; color: #aaa; }
        .content { background: #fff; padding: 0; border-radius: 0 0 8px 8px; }
        .article { padding: 20px 24px; border-bottom: 1px solid #eee; }
        .article:last-child { border-bottom: none; }
        .article h2 { margin: 0 0 8px; font-size: 16px; }
        .article h2 a { color: #1a1a2e; text-decoration: none; }
        .article h2 a:hover { text-decoration: underline; }
        .article .meta { font-size: 12px; color: #999; margin-bottom: 8px; }
        .article .summary { font-size: 14px; color: #555; line-height: 1.5; }
        .footer { text-align: center; padding: 16px; font-size: 12px; color: #999; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $sourceName }}</h1>
            <p>{{ count($articles) }} new {{ count($articles) === 1 ? 'article' : 'articles' }} — {{ now()->format('M j, Y') }}</p>
        </div>
        <div class="content">
            @foreach ($articles as $article)
                <div class="article">
                    <h2><a href="{{ $article['url'] }}">{{ $article['title'] }}</a></h2>
                    @if (!empty($article['published_at']))
                        <div class="meta">{{ $article['published_at'] }}</div>
                    @endif
                    @if (!empty($article['summary']))
                        <div class="summary">{{ Str::limit($article['summary'], 300) }}</div>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="footer">
            Sent by <a href="{{ config('app.url') }}">{{ config('app.name') }}</a>
        </div>
    </div>
</body>
</html>
