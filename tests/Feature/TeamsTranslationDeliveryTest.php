<?php

namespace Tests\Feature;

use App\Domain\Article\Contracts\ArticleTranslationService;
use App\Jobs\QueueWebhookDeliveriesJob;
use App\Jobs\SendTeamsMessageJob;
use App\Jobs\TranslateArticleJob;
use App\Models\Article;
use App\Models\Delivery;
use App\Models\Source;
use App\Models\Subscription;
use App\Models\TranslatedArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TeamsTranslationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_webhook_deliveries_batches_translated_teams_messages(): void
    {
        Bus::fake();

        $source = $this->createSource();
        $article = $this->createArticle($source, 'https://github.blog/post');
        $subscription = $this->createTeamsSubscription($source, true, 'uk');

        $job = new QueueWebhookDeliveriesJob(
            sourceId: (string) $source->id,
            channel: 'teams',
            articleCount: 1,
            context: ['article_ids' => [$article->id]],
        );

        $job->handle();

        Bus::assertDispatched(TranslateArticleJob::class, function (TranslateArticleJob $job) use ($article, $subscription): bool {
            return $job->articleId === $article->id
                && $job->language === 'uk'
                && count($job->recipients) === 1
                && $job->recipients[0]['subscription_id'] === $subscription->id
                && $job->recipients[0]['channel'] === 'teams';
        });

        Bus::assertNotDispatched(SendTeamsMessageJob::class);

        $this->assertDatabaseHas('deliveries', [
            'article_id' => $article->id,
            'subscription_id' => $subscription->id,
            'channel' => 'teams',
            'status' => 'queued',
        ]);
    }

    public function test_translate_article_job_dispatches_translated_teams_message(): void
    {
        Bus::fake();

        $source = $this->createSource();
        $article = $this->createArticle($source, 'https://github.blog/post-2');
        $subscription = $this->createTeamsSubscription($source, true, 'uk');
        $delivery = Delivery::query()->create([
            'article_id' => $article->id,
            'subscription_id' => $subscription->id,
            'channel' => 'teams',
            'status' => 'queued',
            'attempts' => 0,
            'queued_at' => now(),
            'meta' => [],
        ]);

        $translated = TranslatedArticle::query()->create([
            'article_id' => $article->id,
            'language' => 'uk',
            'slug' => 'translated-post-uk',
            'title' => 'Перекладений заголовок',
            'content_html' => '<p>Перекладений контент</p>',
            'source_name' => 'GitHub Blog',
            'source_url' => 'https://github.blog/',
            'original_url' => $article->canonical_url,
            'image_url' => 'https://github.blog/translated-image.jpg',
            'translated_at' => now(),
        ]);

        $fakeTranslationService = new class($translated) implements ArticleTranslationService
        {
            public function __construct(private readonly TranslatedArticle $translated) {}

            public function translateArticle(Article $article, string $language): TranslatedArticle
            {
                return $this->translated;
            }

            public function translateUrl(string $url, string $language, string $provider = 'openai'): TranslatedArticle
            {
                return $this->translated;
            }
        };

        $job = new TranslateArticleJob(
            articleId: $article->id,
            language: 'uk',
            recipients: [[
                'subscription_id' => $subscription->id,
                'delivery_id' => $delivery->id,
                'channel' => 'teams',
            ]],
        );

        $job->handle($fakeTranslationService);

        Bus::assertDispatched(SendTeamsMessageJob::class, function (SendTeamsMessageJob $job) use ($subscription, $translated, $article, $delivery): bool {
            return $job->subscriptionId === (string) $subscription->id
                && $job->articleUrl === $translated->publicUrl()
                && $job->message === '🇺🇦 '.$translated->title
                && ($job->context['delivery_id'] ?? null) === $delivery->id
                && ($job->context['article_id'] ?? null) === $article->id
                && ($job->context['translated_article_id'] ?? null) === $translated->id
                && ($job->context['translated_channel'] ?? null) === 'teams';
        });
    }

    private function createSource(): Source
    {
        return Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://github.blog/feed'),
            'source_url' => 'https://github.blog/feed',
            'canonical_url_hash' => hash('sha256', 'https://github.blog/feed'),
            'canonical_url' => 'https://github.blog/feed',
            'source_type' => 'rss',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 100,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
        ]);
    }

    private function createArticle(Source $source, string $url): Article
    {
        return Article::query()->create([
            'source_id' => $source->id,
            'canonical_url_hash' => hash('sha256', $url),
            'canonical_url' => $url,
            'content_hash' => hash('sha256', $url.'-content'),
            'title' => 'Original title',
            'summary' => 'Original summary',
            'image_url' => 'https://github.blog/original-image.jpg',
            'published_at' => now(),
            'discovered_at' => now(),
        ]);
    }

    private function createTeamsSubscription(Source $source, bool $translateEnabled, ?string $translateLanguage): Subscription
    {
        return Subscription::query()->create([
            'source_id' => $source->id,
            'channel' => 'teams',
            'target_hash' => hash('sha256', 'https://example.webhook.office.com/path'),
            'target' => 'https://example.webhook.office.com/path',
            'is_active' => true,
            'translate_enabled' => $translateEnabled,
            'translate_language' => $translateLanguage,
            'config' => [],
        ]);
    }
}