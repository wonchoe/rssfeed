<?php

namespace App\Domain\Delivery\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotApiClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $method, array $payload = []): array
    {
        $endpoint = $this->botEndpoint($method);

        if (is_array($payload['reply_markup'] ?? null)) {
            $payload['reply_markup'] = json_encode(
                $payload['reply_markup'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            $decoded = $response->json();
            $description = is_array($decoded) ? trim((string) ($decoded['description'] ?? '')) : '';
            $message = 'Telegram API request failed with status '.$response->status().'.';

            if ($description !== '') {
                $message .= ' '.$description;
            }

            throw new RuntimeException($message);
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram API returned an unexpected response.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        return $this->post('getFile', [
            'file_id' => $fileId,
        ]);
    }

    public function downloadFile(string $filePath): string
    {
        $botToken = $this->botToken();
        $apiBase = rtrim((string) config('services.telegram.api_base', 'https://api.telegram.org'), '/');
        $endpoint = $apiBase.'/file/bot'.$botToken.'/'.ltrim($filePath, '/');

        $response = Http::timeout(15)->get($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram file download failed with status '.$response->status().'.');
        }

        return (string) $response->body();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        return $this->post('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options));
    }

    public function leaveChat(string $chatId): bool
    {
        $response = $this->post('leaveChat', [
            'chat_id' => $chatId,
        ]);

        return (bool) ($response['result'] ?? false);
    }

    private function botEndpoint(string $method): string
    {
        $apiBase = rtrim((string) config('services.telegram.api_base', 'https://api.telegram.org'), '/');

        return $apiBase.'/bot'.$this->botToken().'/'.$method;
    }

    private function botToken(): string
    {
        $botToken = trim((string) config('services.telegram.bot_token'));

        if ($botToken === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return $botToken;
    }
}
