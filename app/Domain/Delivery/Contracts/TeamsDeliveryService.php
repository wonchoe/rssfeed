<?php

namespace App\Domain\Delivery\Contracts;

use App\Data\Delivery\DeliveryMessageData;

interface TeamsDeliveryService
{
    public function send(DeliveryMessageData $message): void;
}
