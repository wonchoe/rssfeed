<?php

namespace App\Domain\Subscription\Repositories;

use App\Data\Subscription\SubscriptionTargetData;
use App\Domain\Subscription\Contracts\SubscriptionRepository;
use App\Models\Subscription;

class EloquentSubscriptionRepository implements SubscriptionRepository
{
    /**
     * @return list<SubscriptionTargetData>
     */
    public function activeBySource(string $sourceId): array
    {
        return Subscription::query()
            ->where('source_id', $sourceId)
            ->where('is_active', true)
            ->get(['id', 'channel', 'target', 'config'])
            ->map(fn (Subscription $subscription): SubscriptionTargetData => new SubscriptionTargetData(
                subscriptionId: (string) $subscription->id,
                channel: $subscription->channel,
                target: $subscription->target,
                config: (array) ($subscription->config ?? []),
            ))
            ->values()
            ->all();
    }
}
