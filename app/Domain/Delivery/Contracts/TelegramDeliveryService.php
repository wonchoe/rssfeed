<?php

namespace App\Domain\Delivery\Contracts;

use App\Data\Delivery\DeliveryMessageData;

interface TelegramDeliveryService
{
    public function send(DeliveryMessageData $message): void;
}
