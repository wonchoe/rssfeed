<?php

namespace App\Domain\Subscription\Contracts;

use App\Data\Subscription\SubscriptionTargetData;

interface SubscriptionRepository
{
    /**
     * @return array<int, SubscriptionTargetData>
     */
    public function activeBySource(string $sourceId): array;
}
