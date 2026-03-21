<?php

namespace App\Domain\Delivery\Services;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\SlackDeliveryService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SlackWebhookDeliveryService implements SlackDeliveryService
{
    public function send(DeliveryMessageData $message): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $webhookUrl = $message->target;

        if ($webhookUrl === '' || ! str_starts_with($webhookUrl, 'https://hooks.slack.com/')) {
            throw new RuntimeException('Invalid Slack webhook URL.');
        }

        $text = trim($message->title."\n".$message->url);

        $payload = [
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*<{$message->url}|{$message->title}>*",
                    ],
                ],
            ],
            'unfurl_links' => true,
        ];

        if ($message->body !== '') {
            $payload['blocks'][] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => mb_substr($message->body, 0, 2000),
                ],
            ];
        }

        $response = Http::asJson()
            ->timeout(10)
            ->post($webhookUrl, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Slack webhook request failed with status '.$response->status().'.');
        }
    }
}
