<?php

namespace App\Domain\Delivery\Contracts;

use App\Data\Article\NormalizedArticleData;
use App\Data\Subscription\SubscriptionTargetData;

interface DeliveryDispatcher
{
    /**
     * @param  array<int, NormalizedArticleData>  $articles
     * @param  array<int, SubscriptionTargetData>  $subscriptions
     */
    public function dispatch(string $channel, array $articles, array $subscriptions = []): void;
}
