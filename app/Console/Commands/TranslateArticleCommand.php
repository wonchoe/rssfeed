<?php

namespace App\Console\Commands;

use App\Domain\Article\Contracts\ArticleTranslationService;
use Illuminate\Console\Command;

class TranslateArticleCommand extends Command
{
    protected $signature = 'article:translate
        {url : The article URL to translate}
        {--lang=uk : Target language code (uk, en, es, fr, de, etc.)}
        {--provider=openai : Translation provider (openai, xiaomi)}';

    protected $description = 'Translate an article from a URL and generate a local readable page';

    public function handle(ArticleTranslationService $translationService): int
    {
        $url = $this->argument('url');
        $language = $this->option('lang');
        $provider = $this->option('provider');

        $this->info("Fetching and translating article...");
        $this->line("  URL:      {$url}");
        $this->line("  Language: {$language}");
        $this->line("  Provider: {$provider}");
        $this->newLine();

        try {
            $translated = $translationService->translateUrl($url, $language, $provider);

            $this->info('Translation complete!');
            $this->newLine();
            $this->line("  Title:    {$translated->title}");
            $this->line("  Slug:     {$translated->slug}");
            $this->line("  Language: {$translated->language}");
            $this->line("  Source:   {$translated->source_name}");
            $this->line("  Image:    ".($translated->image_url ?? 'none'));
            $this->newLine();
            $this->info("  View at: {$translated->publicUrl()}");
            $this->newLine();

            $contentPreview = strip_tags($translated->content_html);
            $contentPreview = preg_replace('/\s+/', ' ', $contentPreview);
            $this->line('  Content preview (first 300 chars):');
            $this->line('  '.substr(trim($contentPreview), 0, 300).'...');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Translation failed: {$e->getMessage()}");
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
