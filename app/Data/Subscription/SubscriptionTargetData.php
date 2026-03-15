<?php

namespace App\Data\Subscription;

readonly class SubscriptionTargetData
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $subscriptionId,
        public string $channel,
        public string $target,
        public array $config = [],
    ) {}
}
