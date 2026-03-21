<?php

namespace App\Domain\Delivery\Services;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\DiscordDeliveryService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DiscordWebhookDeliveryService implements DiscordDeliveryService
{
    public function send(DeliveryMessageData $message): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $webhookUrl = $message->target;

        if ($webhookUrl === '' || ! str_starts_with($webhookUrl, 'https://discord.com/api/webhooks/')) {
            throw new RuntimeException('Invalid Discord webhook URL.');
        }

        $embed = [
            'title' => mb_substr($message->title, 0, 256),
            'url' => $message->url,
            'color' => 0x5865F2, // Discord blurple
        ];

        if ($message->body !== '') {
            $embed['description'] = mb_substr($message->body, 0, 2048);
        }

        $payload = [
            'embeds' => [$embed],
        ];

        $response = Http::asJson()
            ->timeout(10)
            ->post($webhookUrl, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Discord webhook request failed with status '.$response->status().'.');
        }
    }
}
