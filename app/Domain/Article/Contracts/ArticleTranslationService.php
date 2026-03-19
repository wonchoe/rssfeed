<?php

namespace App\Domain\Article\Contracts;

use App\Models\Article;
use App\Models\TranslatedArticle;

interface ArticleTranslationService
{
    public function translateArticle(Article $article, string $language): TranslatedArticle;

    public function translateUrl(string $url, string $language, string $provider = 'openai'): TranslatedArticle;
}
