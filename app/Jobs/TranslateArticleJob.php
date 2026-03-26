<?php

namespace App\Jobs;

use App\Domain\Article\Contracts\ArticleTranslationService;
use App\Models\Article;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranslateArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
        * @param  array<int, array{subscription_id: int, delivery_id: int, channel?: string}>  $recipients
     */
    public function __construct(
        public readonly int $articleId,
        public readonly string $language,
        public readonly array $recipients,
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

        if ($article === null) {
            return;
        }

        $translated = $translationService->translateArticle($article, $this->language);

        $flagMap = [
            'uk' => '🇺🇦', 'en' => '🇬🇧', 'es' => '🇪🇸', 'fr' => '🇫🇷',
            'de' => '🇩🇪', 'it' => '🇮🇹', 'pt' => '🇵🇹', 'ja' => '🇯🇵',
            'ko' => '🇰🇷', 'zh' => '🇨🇳', 'ar' => '🇸🇦', 'ru' => '🇷🇺',
            'pl' => '🇵🇱', 'nl' => '🇳🇱', 'tr' => '🇹🇷',
        ];

        $flag = $flagMap[$this->language] ?? '🌐';
        $message = $flag.' '.$translated->title;
        $articleUrl = $translated->publicUrl();

        foreach ($this->recipients as $recipient) {
            $subscription = Subscription::query()->find($recipient['subscription_id']);

            if ($subscription === null) {
                continue;
            }

            $channel = (string) ($recipient['channel'] ?? $subscription->channel);

            $jobClass = match ($channel) {
                'telegram' => SendTelegramMessageJob::class,
                'teams' => SendTeamsMessageJob::class,
                default => null,
            };

            if ($jobClass === null) {
                continue;
            }

            $jobClass::dispatch(
                subscriptionId: (string) $subscription->id,
                articleUrl: $articleUrl,
                message: $message,
                context: [
                    'delivery_id' => $recipient['delivery_id'],
                    'article_id' => $article->id,
                    'source_id' => $subscription->source_id,
                    'translated_article_id' => $translated->id,
                    'translated_channel' => $channel,
                    'pipeline_stage' => 'deliver',
                ],
            )->onQueue('delivery');
        }
    }
}
