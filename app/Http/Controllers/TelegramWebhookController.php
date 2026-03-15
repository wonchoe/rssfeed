<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Services\TelegramBotApiClient;
use App\Domain\Delivery\Services\TelegramLinkingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function handle(
        Request $request,
        TelegramLinkingService $telegramLinkingService,
        TelegramBotApiClient $telegramBotApiClient,
    ): JsonResponse {
        if (! $this->isAuthorizedWebhook($request)) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized webhook.',
            ], 401);
        }

        $payload = $request->all();

        try {
            $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;

            if ($message !== null) {
                $this->handleMessage($message, $telegramLinkingService, $telegramBotApiClient);
            }

            $membershipUpdate = is_array($payload['my_chat_member'] ?? null) ? $payload['my_chat_member'] : null;

            if ($membershipUpdate !== null) {
                $this->handleMembershipUpdate($membershipUpdate, $telegramLinkingService);
            }
        } catch (Throwable $exception) {
            Log::warning('Telegram webhook handling failed.', [
                'message' => $exception->getMessage(),
                'payload' => $payload,
            ]);
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(
        array $message,
        TelegramLinkingService $telegramLinkingService,
        TelegramBotApiClient $telegramBotApiClient,
    ): void {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];

        if (($chat['type'] ?? null) !== 'private' || ($from['is_bot'] ?? false) === true) {
            return;
        }

        $chatId = $this->normalizeTelegramId($chat['id'] ?? null);

        if ($chatId === '') {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $connectToken = $this->extractConnectToken($text);

        if ($connectToken !== null) {
            $this->handleConnectToken(
                chatId: $chatId,
                connectToken: $connectToken,
                from: $from,
                telegramLinkingService: $telegramLinkingService,
                telegramBotApiClient: $telegramBotApiClient,
            );

            return;
        }

        $chatShared = is_array($message['chat_shared'] ?? null) ? $message['chat_shared'] : null;

        if ($chatShared !== null) {
            $this->handleChatShared(
                chatId: $chatId,
                from: $from,
                chatShared: $chatShared,
                telegramLinkingService: $telegramLinkingService,
            );

            return;
        }

        if ($text === '/start' || str_starts_with($text, '/start@')) {
            $telegramBotApiClient->sendMessage(
                $chatId,
                'Your Telegram chat is ready. Add the bot to a group or channel, then return to rss.cursor.style and verify the destination.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function handleConnectToken(
        string $chatId,
        string $connectToken,
        array $from,
        TelegramLinkingService $telegramLinkingService,
        TelegramBotApiClient $telegramBotApiClient,
    ): void {
        try {
            $link = $telegramLinkingService->completeConnectRequest($connectToken, $from);
        } catch (RuntimeException $exception) {
            $telegramBotApiClient->sendMessage($chatId, $exception->getMessage());

            return;
        }

        if ($link === null) {
            $telegramBotApiClient->sendMessage(
                $chatId,
                'This connect link is invalid or expired. Go back to rss.cursor.style and start Connect Telegram again.'
            );

            return;
        }

        $telegramBotApiClient->sendMessage(
            $chatId,
            'Telegram connected for '.$link->displayHandle().".\n\nAdd the bot to your group or channel, then return to rss.cursor.style and add that destination."
        );
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $chatShared
     */
    private function handleChatShared(
        string $chatId,
        array $from,
        array $chatShared,
        TelegramLinkingService $telegramLinkingService,
    ): void {
        try {
            $telegramChat = $telegramLinkingService->completeChatSelection($from, $chatShared);
        } catch (RuntimeException $exception) {
            $telegramLinkingService->sendKeyboardRemoval($chatId, $exception->getMessage());

            return;
        }

        if ($telegramChat === null) {
            $telegramLinkingService->sendKeyboardRemoval(
                $chatId,
                'This destination selection is no longer valid. Start the add flow again from rss.cursor.style.'
            );

            return;
        }

        $telegramLinkingService->sendKeyboardRemoval(
            $chatId,
            'Linked "'.$telegramChat->displayName().'" successfully. You can now use this '.$telegramChat->kindLabel().' as a delivery target in rss.cursor.style.'
        );
    }

    /**
     * @param  array<string, mixed>  $membershipUpdate
     */
    private function handleMembershipUpdate(
        array $membershipUpdate,
        TelegramLinkingService $telegramLinkingService,
    ): void {
        $chat = is_array($membershipUpdate['chat'] ?? null) ? $membershipUpdate['chat'] : [];
        $from = is_array($membershipUpdate['from'] ?? null) ? $membershipUpdate['from'] : [];
        $oldChatMember = is_array($membershipUpdate['old_chat_member'] ?? null)
            ? $membershipUpdate['old_chat_member']
            : [];
        $newChatMember = is_array($membershipUpdate['new_chat_member'] ?? null)
            ? $membershipUpdate['new_chat_member']
            : [];

        $telegramLinkingService->recordBotMembershipUpdate($chat, $from, $oldChatMember, $newChatMember);
    }

    private function isAuthorizedWebhook(Request $request): bool
    {
        $secret = trim((string) config('services.telegram.webhook_secret'));

        if ($secret === '') {
            return true;
        }

        return hash_equals(
            $secret,
            trim((string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))
        );
    }

    private function extractConnectToken(string $text): ?string
    {
        if (preg_match('/^\/start(?:@\w+)?\s+link_([A-Za-z0-9]+)$/', $text, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function normalizeTelegramId(mixed $value): string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
