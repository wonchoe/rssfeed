<?php

namespace App\Domain\Delivery\Contracts;

use App\Data\Delivery\DeliveryMessageData;

interface DiscordDeliveryService
{
    public function send(DeliveryMessageData $message): void;
}
