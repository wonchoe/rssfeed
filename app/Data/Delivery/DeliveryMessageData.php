<?php

namespace App\Data\Delivery;

readonly class DeliveryMessageData
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $channel,
        public string $target,
        public string $title,
        public string $body,
        public string $url,
        public array $context = [],
    ) {}
}
