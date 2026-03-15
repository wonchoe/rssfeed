<?php

namespace App\Domain\Delivery\Services;

use App\Data\Article\NormalizedArticleData;
use App\Data\Delivery\DeliveryMessageData;
use App\Data\Subscription\SubscriptionTargetData;
use App\Domain\Delivery\Contracts\DeliveryDispatcher;
use App\Domain\Delivery\Contracts\TelegramDeliveryService;
use Illuminate\Support\Facades\Log;

class ChannelDeliveryDispatcher implements DeliveryDispatcher
{
    public function __construct(
        private readonly TelegramDeliveryService $telegramDeliveryService,
    ) {}

    /**
     * @param  array<int, NormalizedArticleData>  $articles
     * @param  array<int, SubscriptionTargetData>  $subscriptions
     */
    public function dispatch(string $channel, array $articles, array $subscriptions = []): void
    {
        if ($channel !== 'telegram') {
            Log::warning('Unsupported channel requested for delivery dispatch.', [
                'channel' => $channel,
            ]);

            return;
        }

        foreach ($subscriptions as $subscription) {
            foreach ($articles as $article) {
                $this->telegramDeliveryService->send(new DeliveryMessageData(
                    channel: 'telegram',
                    target: $subscription->target,
                    title: $article->title,
                    body: $article->summary ?? '',
                    url: $article->canonicalUrl,
                    context: [
                        'subscription_id' => $subscription->subscriptionId,
                    ],
                ));
            }
        }
    }
}
