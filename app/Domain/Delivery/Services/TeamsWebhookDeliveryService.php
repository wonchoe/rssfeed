<?php

namespace App\Domain\Delivery\Services;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\TeamsDeliveryService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TeamsWebhookDeliveryService implements TeamsDeliveryService
{
    public function send(DeliveryMessageData $message): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $webhookUrl = $message->target;

        if ($webhookUrl === '' || ! $this->isValidTeamsWebhookUrl($webhookUrl)) {
            throw new RuntimeException('Invalid Microsoft Teams webhook URL.');
        }

        // Adaptive Card payload for Teams Workflows (Power Automate) connector
        $payload = [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [
                            [
                                'type' => 'TextBlock',
                                'size' => 'Medium',
                                'weight' => 'Bolder',
                                'text' => mb_substr($message->title, 0, 256),
                            ],
                            [
                                'type' => 'TextBlock',
                                'text' => $message->body !== '' ? mb_substr($message->body, 0, 2000) : $message->url,
                                'wrap' => true,
                            ],
                        ],
                        'actions' => [
                            [
                                'type' => 'Action.OpenUrl',
                                'title' => 'Read Article',
                                'url' => $message->url,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::asJson()
            ->timeout(10)
            ->post($webhookUrl, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Teams webhook request failed with status '.$response->status().'.');
        }
    }

    private function isValidTeamsWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = $parsed['host'] ?? '';

        // Modern Teams Workflows (Power Automate) connectors
        if (str_ends_with($host, '.logic.azure.com')) {
            return true;
        }

        // Legacy Office 365 connectors (webhook.office.com)
        if (str_ends_with($host, '.office.com') || str_ends_with($host, '.webhook.office.com')) {
            return true;
        }

        return false;
    }
}
