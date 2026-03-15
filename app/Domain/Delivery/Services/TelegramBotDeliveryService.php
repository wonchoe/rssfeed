<?php

namespace App\Domain\Delivery\Services;

use App\Data\Delivery\DeliveryMessageData;
use App\Domain\Delivery\Contracts\TelegramDeliveryService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotDeliveryService implements TelegramDeliveryService
{
    public function send(DeliveryMessageData $message): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $botToken = trim((string) config('services.telegram.bot_token'));

        if ($botToken === '') {
            if (app()->environment(['local', 'testing'])) {
                return;
            }

            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $apiBase = rtrim((string) config('services.telegram.api_base', 'https://api.telegram.org'), '/');
        $endpoint = $apiBase.'/bot'.$botToken.'/sendMessage';

        $payload = [
            'chat_id' => $message->target,
            'text' => trim($message->title."\n".$message->url."\n\n".$message->body),
            'disable_web_page_preview' => false,
        ];

        $response = Http::asForm()
            ->timeout(10)
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram API request failed with status '.$response->status().'.');
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram API returned an unexpected response.');
        }
    }
}
