<?php

namespace App\Domain\Delivery\Contracts;

use App\Data\Delivery\DeliveryMessageData;

interface SlackDeliveryService
{
    public function send(DeliveryMessageData $message): void;
}
