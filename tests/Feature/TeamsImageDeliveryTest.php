<?php

namespace Tests\Feature;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\TeamsDeliveryService;
use App\Jobs\SendTeamsMessageJob;
use App\Models\Article;
use App\Models\Source;
use App\Models\Subscription;
use App\Support\ArticlePageEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamsImageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_teams_job_passes_article_image_to_delivery_service(): void
    {
        $source = Source::query()->create([
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

        $article = Article::query()->create([
            'source_id' => $source->id,
            'canonical_url_hash' => hash('sha256', 'https://github.blog/post'),
            'canonical_url' => 'https://github.blog/post',
            'content_hash' => hash('sha256', 'post'),
            'title' => 'GitHub post',
            'summary' => 'Post summary',
            'image_url' => 'https://github.blog/image.jpg',
            'published_at' => now(),
            'discovered_at' => now(),
        ]);

        $subscription = Subscription::query()->create([
            'source_id' => $source->id,
            'channel' => 'teams',
            'target_hash' => hash('sha256', 'https://example.webhook.office.com/path'),
            'target' => 'https://example.webhook.office.com/path',
            'is_active' => true,
            'config' => [],
        ]);

        $captured = (object) ['message' => null];

        $fakeService = new class($captured) implements TeamsDeliveryService
        {
            public function __construct(private object $capturedRef) {}

            public function send(DeliveryMessageData $message): void
            {
                $this->capturedRef->message = $message;
            }
        };

        $this->app->instance(TeamsDeliveryService::class, $fakeService);

        $job = new SendTeamsMessageJob(
            subscriptionId: (string) $subscription->id,
            articleUrl: (string) $article->canonical_url,
            message: (string) $article->title,
            context: [
                'article_id' => $article->id,
                'source_id' => $source->id,
            ],
        );

        $job->handle($fakeService, app(ArticlePageEnricher::class));

        $this->assertInstanceOf(DeliveryMessageData::class, $captured->message);
        $this->assertSame('https://github.blog/image.jpg', $captured->message->imageUrl);
        $this->assertSame('GitHub post', $captured->message->title);
        $this->assertSame('https://github.blog/post', $captured->message->url);
    }

    public function test_send_teams_job_drops_unacceptable_image_and_sends_without_it(): void
    {
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', 'https://azure.microsoft.com/feed'),
            'source_url' => 'https://azure.microsoft.com/feed',
            'canonical_url_hash' => hash('sha256', 'https://azure.microsoft.com/feed'),
            'canonical_url' => 'https://azure.microsoft.com/feed',
            'source_type' => 'rss',
            'status' => 'active',
            'usage_state' => 'active',
            'health_score' => 100,
            'health_state' => 'healthy',
            'polling_interval_minutes' => 30,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'canonical_url_hash' => hash('sha256', 'https://azure.microsoft.com/post'),
            'canonical_url' => 'https://azure.microsoft.com/post',
            'content_hash' => hash('sha256', 'post-2'),
            'title' => 'Azure post',
            'summary' => 'Azure summary',
            'image_url' => 'https://cdn-dynmedia-1.microsoft.com/is/content/microsoftcorp/3382405-ConnectToAndTransform',
            'published_at' => now(),
            'discovered_at' => now(),
        ]);

        $subscription = Subscription::query()->create([
            'source_id' => $source->id,
            'channel' => 'teams',
            'target_hash' => hash('sha256', 'https://example.webhook.office.com/path-2'),
            'target' => 'https://example.webhook.office.com/path-2',
            'is_active' => true,
            'config' => [],
        ]);

        $captured = (object) ['message' => null];

        $fakeService = new class($captured) implements TeamsDeliveryService
        {
            public function __construct(private object $capturedRef) {}

            public function send(DeliveryMessageData $message): void
            {
                $this->capturedRef->message = $message;
            }
        };

        $this->app->instance(TeamsDeliveryService::class, $fakeService);

        $job = new SendTeamsMessageJob(
            subscriptionId: (string) $subscription->id,
            articleUrl: (string) $article->canonical_url,
            message: (string) $article->title,
            context: [
                'article_id' => $article->id,
                'source_id' => $source->id,
            ],
        );

        $job->handle($fakeService, app(ArticlePageEnricher::class));

        $this->assertInstanceOf(DeliveryMessageData::class, $captured->message);
        $this->assertNull($captured->message->imageUrl);
        $this->assertSame('Azure post', $captured->message->title);
    }
}