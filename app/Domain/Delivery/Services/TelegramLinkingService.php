<?php

namespace App\Domain\Delivery\Services;

use App\Models\TelegramChat;
use App\Models\TelegramChatRequest;
use App\Models\TelegramConnectRequest;
use App\Models\TelegramUserLink;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramLinkingService
{
    public function __construct(
        private readonly TelegramBotApiClient $telegramBotApiClient,
        private readonly TelegramChatAvatarService $telegramChatAvatarService,
    ) {}

    public function createConnectUrl(User $user): string
    {
        $botUsername = trim((string) config('services.telegram.bot_username'));

        if ($botUsername === '') {
            throw new RuntimeException('TELEGRAM_BOT_USERNAME is not configured.');
        }

        TelegramConnectRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'expired',
            ]);

        $request = TelegramConnectRequest::query()->create([
            'user_id' => $user->id,
            'token' => Str::lower(Str::random(40)),
            'status' => 'pending',
            'expires_at' => now()->addMinutes((int) config('services.telegram.link_token_ttl_minutes', 15)),
        ]);

        return 'https://t.me/'.$botUsername.'?start=link_'.$request->token;
    }

    public function completeConnectRequest(string $token, array $telegramUserPayload): ?TelegramUserLink
    {
        $request = TelegramConnectRequest::query()
            ->where('token', $token)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($request === null) {
            return null;
        }

        $telegramUserId = $this->normalizeTelegramId($telegramUserPayload['id'] ?? null);

        if ($telegramUserId === '') {
            return null;
        }

        $existingLink = TelegramUserLink::query()
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if ($existingLink !== null && $existingLink->user_id !== $request->user_id) {
            throw new RuntimeException('This Telegram account is already linked to another user.');
        }

        $link = TelegramUserLink::query()->updateOrCreate(
            [
                'user_id' => $request->user_id,
            ],
            [
                'telegram_user_id' => $telegramUserId,
                'username' => $this->cleanText($telegramUserPayload['username'] ?? null),
                'first_name' => $this->cleanText($telegramUserPayload['first_name'] ?? null),
                'last_name' => $this->cleanText($telegramUserPayload['last_name'] ?? null),
                'language_code' => $this->cleanText($telegramUserPayload['language_code'] ?? null),
                'connected_at' => $existingLink?->connected_at ?? now(),
                'last_seen_at' => now(),
                'meta' => [
                    'telegram_user' => $telegramUserPayload,
                ],
            ]
        );

        $request->update([
            'status' => 'completed',
            'completed_at' => now(),
            'meta' => [
                'telegram_user_id' => $telegramUserId,
            ],
        ]);

        return $link;
    }

    public function claimDiscoveredChats(User $user, string $chatKind = 'group'): int
    {
        $link = $user->telegramUserLink;

        if ($link === null) {
            throw new RuntimeException('Connect your Telegram account first.');
        }

        $chats = TelegramChat::query()
            ->whereNull('owner_user_id')
            ->where('bot_is_member', true)
            ->whereIn('chat_type', $this->chatTypesForKind($chatKind))
            ->where('added_by_telegram_user_id', $link->telegram_user_id)
            ->get();

        if ($chats->isEmpty()) {
            return 0;
        }

        foreach ($chats as $chat) {
            $chat->update([
                'owner_user_id' => $user->id,
                'linked_at' => $chat->linked_at ?? now(),
                'last_seen_at' => now(),
            ]);
        }

        return $chats->count();
    }

    public function sendChatSelectionPrompt(User $user, string $chatKind = 'group'): TelegramChatRequest
    {
        $link = $user->telegramUserLink;

        if ($link === null) {
            throw new RuntimeException('Connect your Telegram account first.');
        }

        TelegramChatRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'expired',
            ]);

        $request = TelegramChatRequest::query()->create([
            'user_id' => $user->id,
            'telegram_user_link_id' => $link->id,
            'request_id' => random_int(1, 2147483647),
            'status' => 'pending',
            'expires_at' => now()->addMinutes((int) config('services.telegram.chat_request_ttl_minutes', 10)),
            'meta' => [
                'chat_kind' => $chatKind,
            ],
        ]);

        $isChannel = $chatKind === 'channel';
        $noun = $isChannel ? 'channel' : 'group';
        $requestChatPayload = array_filter([
            'request_id' => $request->request_id,
            'chat_is_channel' => $isChannel,
            'bot_is_member' => $isChannel ? null : true,
            'request_title' => true,
            'request_username' => true,
            'request_photo' => true,
            'user_administrator_rights' => $isChannel
                ? [
                    'can_post_messages' => true,
                ]
                : null,
            'bot_administrator_rights' => $isChannel
                ? [
                    'can_post_messages' => true,
                ]
                : null,
        ], static fn (mixed $value): bool => $value !== null);

        $this->telegramBotApiClient->sendMessage(
            $link->telegram_user_id,
            "Choose the Telegram {$noun} where rss.cursor.style should send updates.\n\nIf you just added the bot, it should appear in the selector.",
            [
                'reply_markup' => [
                    'keyboard' => [
                        [[
                            'text' => $isChannel ? 'Choose channel' : 'Choose group',
                            'request_chat' => $requestChatPayload,
                        ]],
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ],
            ]
        );

        return $request;
    }

    public function completeChatSelection(array $telegramUserPayload, array $chatSharedPayload): ?TelegramChat
    {
        $telegramUserId = $this->normalizeTelegramId($telegramUserPayload['id'] ?? null);
        $requestId = $this->normalizeRequestId($chatSharedPayload['request_id'] ?? null);

        if ($telegramUserId === '' || $requestId === null) {
            return null;
        }

        $request = TelegramChatRequest::query()
            ->with('telegramUserLink')
            ->where('request_id', $requestId)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($request === null || $request->telegramUserLink->telegram_user_id !== $telegramUserId) {
            return null;
        }

        $chatId = $this->normalizeTelegramId($chatSharedPayload['chat_id'] ?? null);

        if ($chatId === '') {
            return null;
        }

        $existingChat = TelegramChat::query()
            ->where('telegram_chat_id', $chatId)
            ->first();
        $chatKind = (string) (($request->meta ?? [])['chat_kind'] ?? 'group');

        if (
            $existingChat !== null &&
            $existingChat->owner_user_id !== null &&
            $existingChat->owner_user_id !== $request->user_id
        ) {
            throw new RuntimeException('This Telegram destination is already linked to another user.');
        }

        $chat = TelegramChat::query()->updateOrCreate(
            [
                'telegram_chat_id' => $chatId,
            ],
            [
                'owner_user_id' => $request->user_id,
                'chat_type' => $existingChat?->chat_type ?? $this->defaultChatTypeForKind($chatKind),
                'title' => $this->cleanText($chatSharedPayload['title'] ?? null) ?? $existingChat?->title,
                'username' => $this->cleanText($chatSharedPayload['username'] ?? null) ?? $existingChat?->username,
                'avatar_path' => $existingChat?->avatar_path,
                'is_forum' => (bool) ($existingChat?->is_forum ?? false),
                'bot_membership_status' => $existingChat?->bot_membership_status ?? 'member',
                'bot_is_member' => true,
                'added_by_telegram_user_id' => $existingChat?->added_by_telegram_user_id ?? $telegramUserId,
                'discovered_at' => $existingChat?->discovered_at ?? now(),
                'linked_at' => now(),
                'last_seen_at' => now(),
                'meta' => array_filter([
                    'chat_shared' => $chatSharedPayload,
                    'existing_meta' => $existingChat?->meta,
                ]),
            ]
        );

        $photoPayload = $chatSharedPayload['photo'] ?? [];

        if (is_array($photoPayload) && $photoPayload !== []) {
            $photoSizes = array_values(array_filter(
                $photoPayload,
                static fn (mixed $item): bool => is_array($item),
            ));

            if ($photoSizes !== []) {
                $this->telegramChatAvatarService->cacheFromPhotoSizes($chat, $photoSizes);
                $chat->refresh();
            }
        }

        $request->update([
            'status' => 'completed',
            'completed_at' => now(),
            'meta' => [
                'telegram_chat_id' => $chatId,
                'chat_kind' => $chatKind,
            ],
        ]);

        return $chat;
    }

    public function recordBotMembershipUpdate(
        array $chatPayload,
        array $telegramUserPayload,
        array $oldMemberPayload,
        array $newMemberPayload,
    ): ?TelegramChat {
        $chatId = $this->normalizeTelegramId($chatPayload['id'] ?? null);
        $chatType = trim((string) ($chatPayload['type'] ?? ''));

        if ($chatId === '' || $chatType === '') {
            return null;
        }

        $existingChat = TelegramChat::query()
            ->where('telegram_chat_id', $chatId)
            ->first();

        $previousStatus = trim((string) ($oldMemberPayload['status'] ?? ''));
        $membershipStatus = trim((string) ($newMemberPayload['status'] ?? ''));
        $wasBotMember = in_array($previousStatus, ['member', 'administrator'], true);
        $isBotMember = in_array($membershipStatus, ['member', 'administrator'], true);
        $addedByTelegramUserId = $this->normalizeTelegramId($telegramUserPayload['id'] ?? null);
        $linkingUserId = (! $wasBotMember && $isBotMember && $addedByTelegramUserId !== '')
            ? $addedByTelegramUserId
            : $existingChat?->added_by_telegram_user_id;

        return TelegramChat::query()->updateOrCreate(
            [
                'telegram_chat_id' => $chatId,
            ],
            [
                'chat_type' => $chatType,
                'title' => $this->cleanText($chatPayload['title'] ?? null),
                'username' => $this->cleanText($chatPayload['username'] ?? null),
                'is_forum' => (bool) ($chatPayload['is_forum'] ?? false),
                'bot_membership_status' => $membershipStatus !== '' ? $membershipStatus : null,
                'bot_is_member' => $isBotMember,
                'added_by_telegram_user_id' => $linkingUserId,
                'discovered_at' => $existingChat?->discovered_at ?? now(),
                'last_seen_at' => now(),
                'meta' => [
                    'chat' => $chatPayload,
                    'old_member' => $oldMemberPayload,
                    'new_member' => $newMemberPayload,
                ],
            ]
        );
    }

    /**
     * @return Collection<int, TelegramChat>
     */
    public function linkedChatsFor(User $user): Collection
    {
        return $user->telegramChats()
            ->withCount('subscriptions')
            ->where('bot_is_member', true)
            ->orderByDesc('linked_at')
            ->orderBy('title')
            ->get();
    }

    /**
     * @return Collection<int, TelegramChat>
     */
    public function linkedChatsForKind(User $user, string $chatKind): Collection
    {
        return $user->telegramChats()
            ->withCount('subscriptions')
            ->where('bot_is_member', true)
            ->whereIn('chat_type', $this->chatTypesForKind($chatKind))
            ->orderByDesc('linked_at')
            ->orderBy('title')
            ->get();
    }

    public function unlinkChat(User $user, TelegramChat $chat): bool
    {
        if ($chat->owner_user_id !== $user->id) {
            throw new RuntimeException('This Telegram destination does not belong to your account.');
        }

        $subscriptionCount = $chat->subscriptions()->count();

        if ($subscriptionCount > 0) {
            $label = $subscriptionCount === 1 ? 'feed' : 'feeds';

            throw new RuntimeException(
                'This Telegram destination is still used by '.$subscriptionCount.' '.$label.'. Retarget or remove those subscriptions first.'
            );
        }

        $removedFromTelegram = ! $chat->bot_is_member;

        if ($chat->bot_is_member) {
            try {
                $removedFromTelegram = $this->telegramBotApiClient->leaveChat($chat->telegram_chat_id);
            } catch (RuntimeException) {
                $removedFromTelegram = false;
            }
        }

        $chat->update([
            'owner_user_id' => null,
            'bot_membership_status' => 'left',
            'bot_is_member' => false,
            'linked_at' => null,
            'last_seen_at' => now(),
        ]);

        return $removedFromTelegram;
    }

    public function sendKeyboardRemoval(string $chatId, string $text): void
    {
        $this->telegramBotApiClient->sendMessage($chatId, $text, [
            'reply_markup' => [
                'remove_keyboard' => true,
            ],
        ]);
    }

    private function normalizeTelegramId(mixed $value): string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            $normalized = trim((string) $value);

            return $normalized !== '' ? $normalized : '';
        }

        return '';
    }

    private function normalizeRequestId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function cleanText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? Str::limit($text, 255, '') : null;
    }

    /**
     * @return list<string>
     */
    private function chatTypesForKind(string $chatKind): array
    {
        return $chatKind === 'channel'
            ? ['channel']
            : ['group', 'supergroup'];
    }

    private function defaultChatTypeForKind(string $chatKind): string
    {
        return $chatKind === 'channel' ? 'channel' : 'group';
    }
}
