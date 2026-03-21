<?php

namespace App\Domain\Article\Services;

use App\Domain\Article\Contracts\ArticleTranslationService;
use App\Models\Article;
use App\Models\TranslatedArticle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenAiArticleTranslationService implements ArticleTranslationService
{
    public function translateArticle(Article $article, string $language): TranslatedArticle
    {
        $existing = TranslatedArticle::query()
            ->where('article_id', $article->id)
            ->where('language', $language)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $lockKey = "translate:{$article->id}:{$language}";

        return Cache::lock($lockKey, 300)->block(300, function () use ($article, $language): TranslatedArticle {
            // Re-check after acquiring lock — another worker may have finished
            $existing = TranslatedArticle::query()
                ->where('article_id', $article->id)
                ->where('language', $language)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Try Jina Reader for better content extraction
            $markdown = $this->fetchViaJinaReader($article->canonical_url);

            $provider = (string) config('services.translation.provider', 'openai');

            if ($markdown !== null) {
                $contentMarkdown = $this->cleanJinaMarkdown($markdown);
                $translatedContent = $this->translateWithFallback(
                    $provider, 'markdown', $article->title, $contentMarkdown, $language, $article->canonical_url
                );
            } else {
                $contentHtml = $this->fetchArticleContent($article);
                $translatedContent = $this->translateWithFallback(
                    $provider, 'html', $article->title, $contentHtml, $language, $article->canonical_url
                );
            }

            $slug = $this->generateSlug($article, $language);

            return TranslatedArticle::query()->create([
                'article_id' => $article->id,
                'language' => $language,
                'slug' => $slug,
                'title' => $translatedContent['title'],
                'content_html' => $translatedContent['content_html'],
                'source_name' => $article->source?->domain ?? parse_url($article->canonical_url, PHP_URL_HOST),
                'source_url' => $article->source?->canonical_url ?? $article->source?->source_url,
                'original_url' => $article->canonical_url,
                'image_url' => $article->image_url,
                'translated_at' => now(),
            ]);
        });
    }

    public function translateUrl(string $url, string $language, string $provider = 'openai'): TranslatedArticle
    {
        $urlHash = hash('sha256', $url.'-'.$language.'-'.$provider);

        $existing = TranslatedArticle::query()
            ->where('original_url', $url)
            ->where('language', $language)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $article = Article::query()->where('canonical_url', $url)->first();

        if ($article !== null) {
            return $this->translateArticle($article, $language);
        }

        // Try Jina Reader first for clean content extraction (handles JS-rendered pages)
        $markdown = $this->fetchViaJinaReader($url);

        if ($markdown !== null) {
            $title = $this->extractTitleFromMarkdown($markdown, $url);
            $imageUrl = $this->extractHeroImageFromMarkdown($markdown, $url);
            $contentMarkdown = $this->cleanJinaMarkdown($markdown);
            $translatedContent = $this->translateWithFallback(
                $provider, 'markdown', $title, $contentMarkdown, $language, $url
            );
        } else {
            // Fallback to direct HTML extraction
            $fullHtml = $this->fetchContentFromUrl($url);
            $title = $this->extractTitle($fullHtml, $url);
            $imageUrl = $this->extractHeroImage($fullHtml, $url);
            $contentHtml = $this->extractArticleBody($fullHtml);
            $contentHtml = $this->fixRelativeUrls($contentHtml, $url);
            $translatedContent = $this->translateWithFallback(
                $provider, 'html', $title, $contentHtml, $language, $url
            );
        }

        $slug = Str::slug(Str::limit($translatedContent['title'], 80, '')).'-'.$language.'-'.substr(md5($urlHash.microtime(true)), 0, 8);

        if ($slug === '' || $slug === '-'.$language.'-') {
            $slug = 'article-'.$language.'-'.substr(md5($urlHash.microtime(true)), 0, 8);
        }

        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';

        return TranslatedArticle::query()->create([
            'article_id' => $article?->id,
            'language' => $language,
            'slug' => $slug,
            'title' => $translatedContent['title'],
            'content_html' => $translatedContent['content_html'],
            'source_name' => $host,
            'source_url' => $url,
            'original_url' => $url,
            'image_url' => $imageUrl,
            'translated_at' => now(),
        ]);
    }

    private function fetchContentFromUrl(string $url): string
    {
        $response = Http::timeout(20)
            ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36')
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch URL: HTTP '.$response->status());
        }

        return $response->body();
    }

    private function extractTitleFromMarkdown(string $markdown, string $url): string
    {
        // Jina often puts title as first H1
        if (preg_match('/^# (.+)$/m', $markdown, $matches)) {
            return trim($matches[1]);
        }

        return parse_url($url, PHP_URL_HOST) ?: 'Untitled';
    }

    private function extractHeroImageFromMarkdown(string $markdown, string $url): ?string
    {
        // Look for the first full-size image (skip tiny icons/avatars by looking at URL patterns)
        preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/', $markdown, $matches);

        foreach ($matches[1] as $imgUrl) {
            // Skip tiny images (Wix thumbnail patterns)
            if (preg_match('/fill\/w_(\d+)/', $imgUrl, $wMatch)) {
                if ((int) $wMatch[1] >= 200) {
                    return $imgUrl;
                }

                continue;
            }

            // For non-Wix images, take the first that looks substantial
            if (! preg_match('/icon|logo|avatar|favicon/i', $imgUrl)) {
                return $imgUrl;
            }
        }

        return null;
    }

    private function extractTitle(string $html, string $url): string
    {
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (trim($title) !== '') {
                return trim($title);
            }
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
            $title = strip_tags($matches[1]);

            if (trim($title) !== '') {
                return trim($title);
            }
        }

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = strip_tags($matches[1]);

            if (trim($title) !== '') {
                return trim($title);
            }
        }

        return parse_url($url, PHP_URL_HOST) ?: 'Untitled';
    }

    private function extractHeroImage(string $html, string $url): ?string
    {
        $parsedBase = parse_url($url);
        $origin = ($parsedBase['scheme'] ?? 'https').'://'.($parsedBase['host'] ?? '');

        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $img = $matches[1];

            if (str_starts_with($img, '/')) {
                return $origin.$img;
            }

            return $img;
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            $img = $matches[1];

            if (str_starts_with($img, '/')) {
                return $origin.$img;
            }

            return $img;
        }

        return null;
    }

    private function fetchArticleContent(Article $article): string
    {
        $payload = $article->normalized_payload ?? [];
        $existingHtml = $payload['content_html'] ?? '';

        if (is_string($existingHtml) && trim($existingHtml) !== '') {
            return $this->fixRelativeUrls($existingHtml, $article->canonical_url);
        }

        $existingText = $payload['content_text'] ?? $article->summary ?? '';

        if (is_string($existingText) && trim($existingText) !== '') {
            return '<p>'.nl2br(e($existingText)).'</p>';
        }

        try {
            $response = Http::timeout(20)
                ->withUserAgent('Mozilla/5.0 (compatible; rsscursor/1.0)')
                ->get($article->canonical_url);

            if (! $response->successful()) {
                return '<p>'.e($article->summary ?? $article->title).'</p>';
            }

            $html = $response->body();
            $extracted = $this->extractArticleBody($html);

            return $this->fixRelativeUrls($extracted, $article->canonical_url);
        } catch (\Throwable) {
            return '<p>'.e($article->summary ?? $article->title).'</p>';
        }
    }

    private function extractArticleBody(string $html): string
    {
        // First strip scripts, styles, and other noise before DOM parsing
        $html = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = (string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = (string) preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html);
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Remove nav, header, footer, sidebar, cookie banners, etc.
        $removeSelectors = [
            '//nav', '//header', '//footer', '//aside',
            '//*[contains(@class, "nav")]',
            '//*[contains(@class, "footer")]',
            '//*[contains(@class, "header")]',
            '//*[contains(@class, "sidebar")]',
            '//*[contains(@class, "cookie")]',
            '//*[contains(@class, "popup")]',
            '//*[contains(@class, "modal")]',
            '//*[contains(@class, "share")]',
            '//*[contains(@class, "social")]',
            '//*[contains(@class, "comments")]',
            '//*[contains(@class, "related")]',
            '//*[contains(@id, "comments")]',
            '//*[@role="navigation"]',
            '//*[@role="banner"]',
            '//*[@role="complementary"]',
        ];

        foreach ($removeSelectors as $selector) {
            $toRemove = $xpath->query($selector);

            if ($toRemove !== false) {
                foreach ($toRemove as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        // Try more specific content selectors first (post body, not the article shell)
        $contentSelectors = [
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "blog-post-content")]',
            '//*[contains(@class, "article-body")]',
            '//*[contains(@class, "article-content")]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "post-body")]',
            '//*[contains(@class, "rich-content")]',
            '//*[contains(@class, "post__content")]',
            '//*[contains(@data-hook, "post-body")]',
            '//*[contains(@data-hook, "post-content")]',
            '//article//div[contains(@class, "content")]',
            '//article',
            '//main',
            '//*[@role="main"]',
        ];

        foreach ($contentSelectors as $selector) {
            $nodes = $xpath->query($selector);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            // Take the node with the most text content (likely the real article)
            $bestNode = null;
            $bestLength = 0;

            foreach ($nodes as $node) {
                $textLength = strlen(trim($node->textContent));

                if ($textLength > $bestLength) {
                    $bestLength = $textLength;
                    $bestNode = $node;
                }
            }

            if ($bestNode !== null && $bestLength > 200) {
                $content = $dom->saveHTML($bestNode);

                if ($content !== false) {
                    return $this->cleanExtractedHtml($content);
                }
            }
        }

        $bodyNodes = $xpath->query('//body');

        if ($bodyNodes !== false && $bodyNodes->length > 0) {
            $content = $dom->saveHTML($bodyNodes->item(0));

            return $content !== false ? $this->cleanExtractedHtml($content) : '';
        }

        return $this->cleanExtractedHtml($html);
    }

    private function cleanExtractedHtml(string $html): string
    {
        // Remove empty divs and spans with no meaningful content
        $html = (string) preg_replace('/<(div|span|section)[^>]*>\s*<\/\1>/is', '', $html);

        // Remove data attributes that bloat HTML (keep src, href, alt, class)
        $html = (string) preg_replace('/\s+data-[\w-]+="[^"]*"/i', '', $html);
        $html = (string) preg_replace("/\s+data-[\w-]+='[^']*'/i", '', $html);

        // Remove inline styles (they add noise)
        $html = (string) preg_replace('/\s+style="[^"]*"/i', '', $html);
        $html = (string) preg_replace("/\s+style='[^']*'/i", '', $html);

        // Remove class attributes (most are framework noise)
        $html = (string) preg_replace('/\s+class="[^"]*"/i', '', $html);
        $html = (string) preg_replace("/\s+class='[^']*'/i", '', $html);

        // Remove custom web component tags but keep their inner content
        $html = (string) preg_replace('/<(?:wow-image|wix-[a-z-]+)[^>]*>/i', '', $html);
        $html = (string) preg_replace('/<\/(?:wow-image|wix-[a-z-]+)>/i', '', $html);

        // Collapse excessive whitespace
        $html = (string) preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    private function fixRelativeUrls(string $html, string $baseUrl): string
    {
        $parsedBase = parse_url($baseUrl);
        $scheme = ($parsedBase['scheme'] ?? 'https');
        $host = ($parsedBase['host'] ?? '');
        $origin = $scheme.'://'.$host;
        $basePath = rtrim(dirname($parsedBase['path'] ?? '/'), '/');

        // Fix href="/..." and src="/..."
        $html = (string) preg_replace_callback(
            '/((?:href|src|poster|data-src)\s*=\s*["\'])(\/?[^"\':\s][^"\']*?)(["\'])/i',
            function (array $matches) use ($origin, $basePath): string {
                $url = $matches[2];

                if (preg_match('#^https?://#i', $url) || str_starts_with($url, '//') || str_starts_with($url, 'data:') || str_starts_with($url, 'mailto:') || str_starts_with($url, '#')) {
                    return $matches[0];
                }

                if (str_starts_with($url, '/')) {
                    return $matches[1].$origin.$url.$matches[3];
                }

                return $matches[1].$origin.$basePath.'/'.$url.$matches[3];
            },
            $html
        );

        return $html;
    }

    private function fetchViaJinaReader(string $url): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'text/markdown',
                    'X-Return-Format' => 'markdown',
                ])
                ->get('https://r.jina.ai/'.$url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();

            return strlen($body) > 200 ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanJinaMarkdown(string $markdown): string
    {
        // Strip metadata lines that Jina sometimes prepends
        $markdown = (string) preg_replace('/^(Published Time|URL Source|Markdown Content):.*$/m', '', $markdown);

        // Remove avatar images (small profile pics)
        $markdown = (string) preg_replace('/!\[Image \d+:.*?avatar\]\([^)]+\)/i', '', $markdown);
        // Remove generic numbered placeholder images with no meaningful alt text
        $markdown = (string) preg_replace('/!\[Image \d+\]\([^)]+\)/i', '', $markdown);

        // Remove "Follow" links/buttons
        $markdown = (string) preg_replace('/\[.*?Follow\]\([^)]+\)/i', '', $markdown);

        // Remove upvote/like patterns
        $markdown = (string) preg_replace('/\[-?\s*\[x?\]\s*Upvote\s*\d*\]\([^)]+\)/i', '', $markdown);
        $markdown = (string) preg_replace('/\[Upvote\s*\d*\]\([^)]+\)/i', '', $markdown);

        // Remove user profile card patterns (name + handle on same line)
        $markdown = (string) preg_replace('/^\s*\[.*?\b(Follow)\b.*?\]\([^)]+\)\s*$/mi', '', $markdown);

        // Remove lines that are just "+N" (avatar overflow counters)
        $markdown = (string) preg_replace('/^\s*\+\d+\s*$/m', '', $markdown);

        $lines = explode("\n", $markdown);
        $cleaned = [];
        $inArticle = false;
        $passedFirstH1 = false;
        $headerNoiseCount = 0;
        $skipPatterns = [
            '/^top of page$/i',
            '/^bottom of page$/i',
            '/^Use tab to navigate/i',
            '/^Search$/i',
            '/^\*\s+More\s*$/i',
            '/^\*\s+\[All Posts\]/i',
        ];

        $noisePatterns = [
            '/^\*?\s*\[?.*avatar.*\]?\s*$/i',
            '/^\s*\w+\s+\w+\s+Follow\s*$/i',          // "Name Handle Follow"
            '/^\s*\[\w+\]\(https:\/\/.*\/\w+\)\s*$/i', // bare profile links
            '/^\s*Enterprise\+?Article\s*$/i',
            '/\bFollow\s*$/i',
            '/^\s*Upvote\s*\d*\s*$/i',
            '/^\s*Like\s*\d*\s*$/i',
            '/^\s*Share\s*$/i',
            '/^\s*Repost\s*$/i',
            '/^\s*Published\s+\d+\s+(hours?|days?|weeks?|months?)\s+ago\s*$/i',
            '/^\s*\d+\s+min\s+read\s*$/i',
        ];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip nav/menu patterns
            $skip = false;
            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // Start capturing after the first H1 that looks like an article title
            if (preg_match('/^# /', $trimmed)) {
                if (! $passedFirstH1) {
                    $passedFirstH1 = true;
                    // Could be site title or article title — skip it (title comes from metadata)
                    continue;
                }
                $inArticle = true;
                continue;
            }

            // Before article body starts, skip noise aggressively (bylines, dates, avatars)
            if ($passedFirstH1 && ! $inArticle) {
                $headerNoiseCount++;
                // After the first H1, look for H2 as the real content start
                if (preg_match('/^## /', $trimmed)) {
                    $inArticle = true;
                    $cleaned[] = $line;
                    continue;
                }
                // Allow max 30 lines of noise, then start treating as content
                if ($headerNoiseCount > 30 && strlen($trimmed) > 80) {
                    $inArticle = true;
                    $cleaned[] = $line;
                    continue;
                }
                continue;
            }

            // Skip noise patterns once in article
            if ($inArticle) {
                $isNoise = false;
                foreach ($noisePatterns as $pattern) {
                    if (preg_match($pattern, $trimmed)) {
                        $isNoise = true;
                        break;
                    }
                }
                if ($isNoise) {
                    continue;
                }
            }

            // Skip author byline block (usually right after second H1)
            if ($inArticle && preg_match('/^\*\s+(!\[Image \d+:.*Writer|.*min read|\w+ \d{1,2}$)/i', $trimmed)) {
                continue;
            }

            // Stop at footer sections (generic patterns)
            if ($inArticle && preg_match('/^#{1,6}\s+(Recent Posts|Related Posts|More Articles|Comments|Share this|Copyright|Terms of Service|Privacy Policy|Discussion|Responses|Leave a comment)$/i', $trimmed)) {
                break;
            }
            if ($inArticle && preg_match('/^bottom of page$/i', $trimmed)) {
                break;
            }

            if ($inArticle) {
                $cleaned[] = $line;
            }
        }

        // If we never entered article mode but have content after first H1, 
        // fall back to everything after first H1
        if (! $inArticle && $passedFirstH1) {
            return trim(implode("\n", array_slice($lines, 1)));
        }

        return trim(implode("\n", $cleaned));
    }

    /**
     * Try the primary provider, fall back to OpenAI if it fails, then return untranslated as last resort.
     *
     * @return array{title: string, content_html: string}
     */
    private function translateWithFallback(
        string $provider,
        string $contentType,
        string $title,
        string $content,
        string $language,
        string $originalUrl,
    ): array {
        // Build the ordered list of strategies to attempt (max 3 total).
        // Each entry is a closure that calls the appropriate translator.
        $strategies = [];

        if ($contentType === 'html' && $provider !== 'xiaomi') {
            $strategies[] = ['label' => $provider,  'fn' => fn () => $this->translateViaOpenAi($title, $content, $language, $originalUrl)];
        } elseif ($provider === 'xiaomi') {
            $strategies[] = ['label' => 'xiaomi',   'fn' => fn () => $this->translateMarkdownViaMimo($title, $content, $language, $originalUrl)];
            $strategies[] = ['label' => 'openai',   'fn' => fn () => $this->translateMarkdownViaOpenAi($title, $content, $language, $originalUrl)];
        } else {
            $strategies[] = ['label' => 'openai',   'fn' => fn () => $this->translateMarkdownViaOpenAi($title, $content, $language, $originalUrl)];
        }

        $bestResult  = null;
        $attemptsDone = 0;

        foreach ($strategies as $strategy) {
            if ($attemptsDone >= 3) {
                break;
            }

            $attemptsDone++;
            $result = ($strategy['fn'])();

            if ($result === null) {
                Log::warning('Translation: provider returned null', [
                    'provider' => $strategy['label'],
                    'url'      => $originalUrl,
                    'language' => $language,
                    'attempt'  => $attemptsDone,
                ]);

                continue;
            }

            $bestResult = $result;

            $verified = $this->verifyTranslationLanguage($result['content_html'], $language);

            if ($verified === true) {
                return $result;
            }

            if ($verified === false) {
                Log::warning('Translation: language verification failed, trying next strategy', [
                    'provider' => $strategy['label'],
                    'url'      => $originalUrl,
                    'language' => $language,
                    'attempt'  => $attemptsDone,
                ]);

                continue;
            }

            // $verified === null means the detector was inconclusive — ask AI
            $plainText = mb_substr(strip_tags($result['content_html']), 0, 400);
            $languageNames = $this->languageMap();
            $languageName  = $languageNames[$language] ?? $language;
            $aiVerified = $this->verifyLanguageWithAi($plainText, $language, $languageName);

            if ($aiVerified) {
                return $result;
            }

            Log::warning('Translation: AI language verification failed, trying next strategy', [
                'provider' => $strategy['label'],
                'url'      => $originalUrl,
                'language' => $language,
                'attempt'  => $attemptsDone,
            ]);
        }

        // All strategies exhausted or failed verification — use best result we have
        if ($bestResult !== null) {
            Log::warning('Translation: all verification attempts failed, using best available result', [
                'url'      => $originalUrl,
                'language' => $language,
                'attempts' => $attemptsDone,
            ]);

            return $bestResult;
        }

        // Nothing worked at all — return untranslated original
        Log::error('Translation: all providers failed, returning untranslated content', [
            'url'      => $originalUrl,
            'language' => $language,
        ]);

        return [
            'title'        => $title,
            'content_html' => $contentType === 'html' ? $content : Str::markdown($content),
        ];
    }

    /**
     * Detect whether translated content is actually in the expected language.
     *
     * Uses patrickschur/language-detection (trigram-based, no API cost).
     *
     * Returns:
     *   true  — library is confident it IS the right language
     *   false — library is confident it is NOT the right language
     *   null  — library is inconclusive (text too short, ambiguous, or unsupported script)
     */
    private function verifyTranslationLanguage(string $html, string $targetLang): ?bool
    {
        $text = trim(strip_tags($html));

        // Too short to judge reliably
        if (mb_strlen($text) < 60) {
            return null;
        }

        // Use up to 600 chars for detection — enough for trigrams, not expensive
        $sample = mb_substr($text, 0, 600);

        try {
            $ld = new \LanguageDetection\Language;
            // top-3 results
            $results = $ld->detect($sample)->limit(0, 3)->close();
        } catch (\Throwable $e) {
            Log::debug('Translation: language detection library threw exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (empty($results)) {
            return null;
        }

        // Normalize library code to our code (zh-Hans / zh-Hant → zh)
        $normalizeCode = static function (string $code): string {
            if (str_starts_with($code, 'zh')) {
                return 'zh';
            }
            // Some libraries return pt-br, pt-pt etc.
            return strtolower(explode('-', $code)[0]);
        };

        $topCode  = $normalizeCode(array_key_first($results));
        $topScore = reset($results);

        // Inconclusive if top score is too low
        if ($topScore < 0.04) {
            return null;
        }

        if ($topCode === $normalizeCode($targetLang)) {
            return true;
        }

        // If second/third result matches and top score isn't by a wide margin, stay inconclusive
        $secondScore = array_values($results)[1] ?? 0.0;

        if (($topScore - $secondScore) < 0.03) {
            return null;
        }

        return false;
    }

    /**
     * Ask the AI to confirm whether a short text snippet is in the expected language.
     * Returns true on confirmed match, false otherwise (including on API failure).
     *
     * Uses gpt-4o-mini with json_object mode — very cheap (~$0.0001 per call).
     */
    private function verifyLanguageWithAi(string $plainTextSnippet, string $targetLang, string $targetLangName): bool
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));

        if ($apiKey === '') {
            return true; // Can't verify — assume OK to avoid blocking delivery
        }

        try {
            $response = Http::timeout(15)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => 'gpt-4o-mini',
                    'max_tokens'      => 10,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        [
                            'role'    => 'system',
                            'content' => 'You are a language detection assistant. Respond only with valid JSON.',
                        ],
                        [
                            'role'    => 'user',
                            'content' => "Is the following text written in {$targetLangName} (language code: {$targetLang})?\n\nText:\n\"{$plainTextSnippet}\"\n\nReturn exactly: {\"ok\": true} if yes, or {\"ok\": false} if no.",
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return true; // Assume OK on API error
            }

            $raw   = data_get($response->json(), 'choices.0.message.content', '');
            $parsed = json_decode((string) $raw, true);

            return (bool) ($parsed['ok'] ?? true);
        } catch (\Throwable $e) {
            Log::debug('Translation: AI language verification request failed', [
                'error' => $e->getMessage(),
            ]);

            return true; // Assume OK on exception — avoid blocking delivery
        }
    }

    /**
     * @return array{title: string, content_html: string}|null

     */

    private function translateMarkdownViaMimo(string $title, string $contentMarkdown, string $language, string $originalUrl): ?array
    {
        $apiKey = trim((string) config('services.xiaomi.token', ''));

        if ($apiKey === '') {
            Log::warning('Translation: Xiaomi API key not configured');

            return null;
        }

        $baseUrl = (string) config('services.xiaomi.base_url', 'https://api.xiaomimimo.com/v1/chat/completions');
        $model   = (string) config('services.xiaomi.model', 'mimo-v2-pro');

        $languageNames = $this->languageMap();
        $languageName  = $languageNames[$language] ?? $language;
        $contentSnippet = Str::limit($contentMarkdown, 60000, '');

        [$systemPrompt, $userPrompt] = $this->buildTranslationPrompts($title, $contentSnippet, $languageName, $originalUrl);

        return $this->callChatApi($baseUrl, $apiKey, $model, $systemPrompt, $userPrompt);
    }

    /**
     * @return array{title: string, content_html: string}|null
     */
    private function translateMarkdownViaOpenAi(string $title, string $contentMarkdown, string $language, string $originalUrl): ?array
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));

        if ($apiKey === '') {
            Log::warning('Translation: OpenAI API key not configured');

            return null;
        }

        $model = trim((string) config('services.openai.translation_model', 'gpt-4o-mini')) ?: 'gpt-4o-mini';

        $languageNames = $this->languageMap();
        $languageName  = $languageNames[$language] ?? $language;
        $contentSnippet = Str::limit($contentMarkdown, 60000, '');

        [$systemPrompt, $userPrompt] = $this->buildTranslationPrompts($title, $contentSnippet, $languageName, $originalUrl);

        return $this->callChatApi(
            'https://api.openai.com/v1/chat/completions',
            $apiKey,
            $model,
            $systemPrompt,
            $userPrompt,
        );
    }

    /**
     * Build the shared system + user prompts for translation.
     *
     * @return array{0: string, 1: string}
     */
    private function buildTranslationPrompts(string $title, string $contentSnippet, string $languageName, string $originalUrl): array
    {
        $systemPrompt = <<<PROMPT
You are a professional translator and content editor. Translate the provided article into {$languageName}.

CRITICAL — CONTENT CLEANING:
The source markdown may contain UI noise scraped from the webpage. You MUST strip all of the following before translating:
- User avatars, profile pictures, and profile links
- "Follow" buttons, "Upvote" buttons, like/share/repost UI elements
- Author card blocks (avatar + name + handle + "Follow")
- Byline metadata (publish dates, "X min read", view counts)
- Navigation breadcrumbs, sidebar links, cookie banners
- Comment sections, "Leave a reply" forms
- Social sharing widgets
- "+N" counters (e.g. "+18" next to avatar lists)
- Any placeholder images with alt text like "Image 1", "Image 2" etc. that are clearly avatars or icons
Only keep images that are actual article illustrations, diagrams, screenshots, or charts.

TRANSLATION RULES:
- Return ONLY a JSON object with "title" and "content_html" keys.
- Translate the ENTIRE article body faithfully. Do NOT shorten, summarize, or skip any sections.
- The translation should read naturally in {$languageName}, not as a word-for-word literal translation.
- The content is provided in Markdown format. Convert it to clean HTML in the output.
- Preserve article images (diagrams, screenshots, charts) exactly: convert ![alt](url) to <img src="url" alt="alt"> tags.
- Preserve ALL links: convert [text](url) to <a href="url" target="_blank">translated text</a> tags.
- Preserve ALL YouTube or other embed URLs in links or iframes exactly as they are.
- Use clean semantic HTML: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <em>, <code>, <pre>.
- Keep proper paragraph breaks for readability.
- Keep product names, brand names, technical terms, and proper nouns in their original language form.
- Do NOT wrap the response in markdown code fences.
- Do NOT add any content that is not in the original article.
- The output HTML should be ready to embed on a styled page (no <html>, <body>, or <head> tags).
PROMPT;

        $userPrompt = json_encode([
            'title'            => $title,
            'content_markdown' => $contentSnippet,
            'target_language'  => $languageName,
            'original_url'     => $originalUrl,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$systemPrompt, $userPrompt];
    }

    /**
     * @return array{title: string, content_html: string}|null
     */
    private function callChatApi(
        string $baseUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
    ): ?array {
        try {
            $response = Http::timeout(300)
                ->withHeaders(['api-key' => $apiKey])
                ->withToken($apiKey)
                ->acceptJson()
                ->post($baseUrl, [
                    'model'                => $model,
                    'max_completion_tokens' => 16000,
                    'response_format'      => ['type' => 'json_object'],
                    'messages'             => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Translation: API call failed with exception', [
                'url' => $baseUrl,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Translation: API returned non-successful status', [
                'url' => $baseUrl,
                'model' => $model,
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            return null;
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            Log::warning('Translation: API returned empty content', [
                'url' => $baseUrl,
                'model' => $model,
            ]);

            return null;
        }

        $decoded = $this->decodeJson($content);

        if (! is_array($decoded)) {
            Log::warning('Translation: API returned invalid JSON', [
                'url' => $baseUrl,
                'model' => $model,
                'raw' => Str::limit($content, 500),
            ]);

            return null;
        }

        $title = is_string($decoded['title'] ?? null) && trim($decoded['title']) !== ''
            ? trim($decoded['title'])
            : null;
        $html = is_string($decoded['content_html'] ?? null) && trim($decoded['content_html']) !== ''
            ? trim($decoded['content_html'])
            : null;

        if ($title === null && $html === null) {
            return null;
        }

        return [
            'title'        => $title ?? '',
            'content_html' => $html ?? '',
        ];
    }

    /**
     * @return array{title: string, content_html: string}|null
     */
    private function translateViaOpenAi(string $title, string $contentHtml, string $language, string $originalUrl): ?array
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));

        if ($apiKey === '') {
            Log::warning('Translation: OpenAI API key not configured (HTML path)');

            return null;
        }

        $model = trim((string) config('services.openai.translation_model', 'gpt-4o-mini'));

        if ($model === '') {
            $model = 'gpt-4o-mini';
        }

        $languageNames = $this->languageMap();
        $languageName = $languageNames[$language] ?? $language;

        $contentSnippet = Str::limit($contentHtml, 48000, '');

        $systemPrompt = <<<PROMPT
You are a professional translator. Translate the provided article into {$languageName}.

Rules:
- Return ONLY a JSON object with "title" and "content_html" keys.
- Translate the ENTIRE article faithfully. Do NOT shorten, summarize, or skip any sections.
- The translation should read naturally in {$languageName}, not as a word-for-word literal translation.
- Preserve ALL <img> tags with their src, alt, and other attributes exactly as they are.
- Preserve ALL <iframe> tags (YouTube embeds, etc.) exactly as they are.
- Preserve ALL <video> and <source> tags exactly as they are.
- Preserve ALL <a> links with their href attributes. Translate only the link text, keep the URL intact.
- Use clean, semantic HTML: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <em>, <code>, <pre>.
- Add proper paragraph breaks for readability. Do not return walls of text.
- Keep product names, brand names, technical terms, and proper nouns in their original form.
- Do NOT wrap the response in markdown code fences.
- Do NOT add any content that is not in the original article.
- The output HTML should be ready to embed on a styled page (no <html>, <body>, or <head> tags).
PROMPT;

        $userPrompt = json_encode([
            'title' => $title,
            'content_html' => $contentSnippet,
            'target_language' => $languageName,
            'original_url' => $originalUrl,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->callChatApi(
            'https://api.openai.com/v1/chat/completions',
            $apiKey,
            $model,
            $systemPrompt,
            $userPrompt,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $content): ?array
    {
        $raw = trim($content);

        if (Str::startsWith($raw, '```')) {
            $raw = (string) preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = (string) preg_replace('/\s*```$/', '', $raw);
            $raw = trim($raw);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function generateSlug(Article $article, string $language): string
    {
        $base = Str::slug(Str::limit($article->title, 80, ''));

        if ($base === '') {
            $base = 'article';
        }

        $hash = substr(md5($article->id.'-'.$language.'-'.microtime(true)), 0, 8);

        return $base.'-'.$language.'-'.$hash;
    }

    /**
     * @return array<string, string>
     */
    private function languageMap(): array
    {
        return [
            'uk' => 'Ukrainian',
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese (Simplified)',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'pl' => 'Polish',
            'nl' => 'Dutch',
            'tr' => 'Turkish',
            'ru' => 'Russian',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'no' => 'Norwegian',
            'cs' => 'Czech',
            'ro' => 'Romanian',
            'hu' => 'Hungarian',
            'el' => 'Greek',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'he' => 'Hebrew',
            'bg' => 'Bulgarian',
        ];
    }
}
