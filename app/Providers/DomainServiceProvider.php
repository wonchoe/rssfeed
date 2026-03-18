<?php

namespace App\Providers;

use App\Domain\Article\Contracts\ArticleNormalizer;
use App\Domain\Article\Contracts\ArticleTranslationService;
use App\Domain\Article\Contracts\DeduplicationService;
use App\Domain\Article\Services\DatabaseDeduplicationService;
use App\Domain\Article\Services\DefaultArticleNormalizer;
use App\Domain\Article\Services\OpenAiArticleTranslationService;
use App\Domain\Delivery\Contracts\DeliveryDispatcher;
use App\Domain\Delivery\Contracts\TelegramDeliveryService;
use App\Domain\Delivery\Services\ChannelDeliveryDispatcher;
use App\Domain\Delivery\Services\TelegramBotDeliveryService;
use App\Domain\Parsing\Contracts\AiSchemaResolver;
use App\Domain\Parsing\Contracts\FeedParserService;
use App\Domain\Parsing\Contracts\HtmlCandidateExtractor;
use App\Domain\Parsing\Contracts\SchemaValidator;
use App\Domain\Parsing\Services\AdaptiveAiSchemaResolver;
use App\Domain\Parsing\Services\BasicSchemaValidator;
use App\Domain\Parsing\Services\DeterministicFeedParserService;
use App\Domain\Parsing\Services\DeterministicHtmlCandidateExtractor;
use App\Domain\Source\Contracts\SourceDiscoveryService;
use App\Domain\Source\Services\HttpSourceDiscoveryService;
use App\Domain\Subscription\Contracts\SubscriptionRepository;
use App\Domain\Subscription\Repositories\EloquentSubscriptionRepository;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(SourceDiscoveryService::class, HttpSourceDiscoveryService::class);
        $this->app->bind(FeedParserService::class, DeterministicFeedParserService::class);
        $this->app->bind(HtmlCandidateExtractor::class, DeterministicHtmlCandidateExtractor::class);
        $this->app->bind(AiSchemaResolver::class, AdaptiveAiSchemaResolver::class);
        $this->app->bind(SchemaValidator::class, BasicSchemaValidator::class);
        $this->app->bind(ArticleNormalizer::class, DefaultArticleNormalizer::class);
        $this->app->bind(ArticleTranslationService::class, OpenAiArticleTranslationService::class);
        $this->app->bind(DeduplicationService::class, DatabaseDeduplicationService::class);
        $this->app->bind(SubscriptionRepository::class, EloquentSubscriptionRepository::class);
        $this->app->bind(TelegramDeliveryService::class, TelegramBotDeliveryService::class);
        $this->app->bind(DeliveryDispatcher::class, ChannelDeliveryDispatcher::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
