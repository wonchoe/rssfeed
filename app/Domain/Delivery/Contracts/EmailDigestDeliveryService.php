<?php

namespace App\Domain\Delivery\Contracts;

interface EmailDigestDeliveryService
{
    /**
     * Send a daily digest email with all pending articles for the given email subscription.
     *
     * @param  int  $subscriptionId
     */
    public function sendDigest(int $subscriptionId): void;
}
