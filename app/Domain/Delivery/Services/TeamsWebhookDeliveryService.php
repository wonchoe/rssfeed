<?php

namespace App\Domain\Delivery\Services;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\TeamsDeliveryService;
use App\Support\WebhookUrlValidator;
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

        if ($webhookUrl === '' || ! WebhookUrlValidator::isValidTeamsWebhookUrl($webhookUrl)) {
            throw new RuntimeException('Invalid Microsoft Teams webhook URL.');
        }

        // Adaptive Card payload for Teams Workflows (Power Automate) connector
        $body = [
            [
                'type' => 'TextBlock',
                'size' => 'Medium',
                'weight' => 'Bolder',
                'text' => mb_substr($message->title, 0, 256),
            ],
        ];

        if (is_string($message->imageUrl) && trim($message->imageUrl) !== '') {
            $body[] = [
                'type' => 'Image',
                'url' => $message->imageUrl,
                'altText' => mb_substr($message->title, 0, 256),
                'size' => 'Stretch',
            ];
        }

        $body[] = [
            'type' => 'TextBlock',
            'text' => $message->body !== '' ? mb_substr($message->body, 0, 2000) : $message->url,
            'wrap' => true,
        ];

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
                        'body' => $body,
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
}
