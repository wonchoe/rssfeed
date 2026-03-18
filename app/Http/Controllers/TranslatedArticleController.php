<?php

namespace App\Http\Controllers;

use App\Models\TranslatedArticle;
use Illuminate\View\View;

class TranslatedArticleController extends Controller
{
    public function show(string $slug): View
    {
        $translatedArticle = TranslatedArticle::query()
            ->where('slug', $slug)
            ->with('article')
            ->firstOrFail();

        return view('articles.show', [
            'translatedArticle' => $translatedArticle,
        ]);
    }
}
