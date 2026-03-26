<?php

namespace App\Domain\Delivery\Services;

use App\Data\Article\NormalizedArticleData;
use App\Data\Delivery\DeliveryMessageData;
use App\Data\Subscription\SubscriptionTargetData;
use App\Domain\Delivery\Contracts\DeliveryDispatcher;
use App\Domain\Delivery\Contracts\DiscordDeliveryService;
use App\Domain\Delivery\Contracts\SlackDeliveryService;
use App\Domain\Delivery\Contracts\TeamsDeliveryService;
use App\Domain\Delivery\Contracts\TelegramDeliveryService;
use Illuminate\Support\Facades\Log;

class ChannelDeliveryDispatcher implements DeliveryDispatcher
{
    public function __construct(
        private readonly TelegramDeliveryService $telegramDeliveryService,
        private readonly SlackDeliveryService $slackDeliveryService,
        private readonly DiscordDeliveryService $discordDeliveryService,
        private readonly TeamsDeliveryService $teamsDeliveryService,
    ) {}

    private const SUPPORTED_CHANNELS = ['telegram', 'slack', 'discord', 'teams'];

    /**
     * @param  array<int, NormalizedArticleData>  $articles
     * @param  array<int, SubscriptionTargetData>  $subscriptions
     */
    public function dispatch(string $channel, array $articles, array $subscriptions = []): void
    {
        if (! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
            Log::warning('Unsupported channel requested for delivery dispatch.', [
                'channel' => $channel,
            ]);

            return;
        }

        foreach ($subscriptions as $subscription) {
            foreach ($articles as $article) {
                $message = new DeliveryMessageData(
                    channel: $channel,
                    target: $subscription->target,
                    title: $article->title,
                    body: $article->summary ?? '',
                    url: $article->canonicalUrl,
                    imageUrl: $article->imageUrl,
                    context: [
                        'subscription_id' => $subscription->subscriptionId,
                    ],
                );

                match ($channel) {
                    'telegram' => $this->telegramDeliveryService->send($message),
                    'slack' => $this->slackDeliveryService->send($message),
                    'discord' => $this->discordDeliveryService->send($message),
                    'teams' => $this->teamsDeliveryService->send($message),
                };
            }
        }
    }
}
