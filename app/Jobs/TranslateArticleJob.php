<?php

namespace App\Jobs;

use App\Domain\Article\Contracts\ArticleTranslationService;
use App\Models\Article;
use App\Models\Delivery;
use App\Models\Subscription;
use App\Models\TranslatedArticle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranslateArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $articleId,
        public readonly int $subscriptionId,
        public readonly string $language,
        public readonly int $deliveryId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(ArticleTranslationService $translationService): void
    {
        $article = Article::query()->find($this->articleId);
        $subscription = Subscription::query()->find($this->subscriptionId);
        $delivery = Delivery::query()->find($this->deliveryId);

        if ($article === null || $subscription === null) {
            return;
        }

        $translated = $translationService->translateArticle($article, $this->language);

        $this->dispatchTelegramMessage($subscription, $article, $translated, $delivery);
    }

    private function dispatchTelegramMessage(
        Subscription $subscription,
        Article $article,
        TranslatedArticle $translated,
        ?Delivery $delivery,
    ): void {
        $languageNames = [
            'uk' => '🇺🇦', 'en' => '🇬🇧', 'es' => '🇪🇸', 'fr' => '🇫🇷',
            'de' => '🇩🇪', 'it' => '🇮🇹', 'pt' => '🇵🇹', 'ja' => '🇯🇵',
            'ko' => '🇰🇷', 'zh' => '🇨🇳', 'ar' => '🇸🇦', 'ru' => '🇷🇺',
            'pl' => '🇵🇱', 'nl' => '🇳🇱', 'tr' => '🇹🇷',
        ];

        $flag = $languageNames[$this->language] ?? '🌐';
        $message = $flag.' '.$translated->title;

        SendTelegramMessageJob::dispatch(
            subscriptionId: (string) $subscription->id,
            articleUrl: $translated->publicUrl(),
            message: $message,
            context: [
                'delivery_id' => $delivery?->id,
                'article_id' => $article->id,
                'source_id' => $subscription->source_id,
                'translated_article_id' => $translated->id,
                'pipeline_stage' => 'deliver',
            ],
        )->onQueue('delivery');
    }
}
