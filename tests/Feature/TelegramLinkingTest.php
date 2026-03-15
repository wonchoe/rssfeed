<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\TelegramChat;
use App\Models\TelegramChatRequest;
use App\Models\TelegramConnectRequest;
use App\Models\TelegramUserLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramLinkingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_link_telegram_account_via_start_payload(): void
    {
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.bot_username', 'rss_cursor_test_bot');
        config()->set('services.telegram.webhook_secret', 'test-webhook-secret');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('telegram.connect'));

        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://t.me/rss_cursor_test_bot?start=link_', $location);

        $connectRequest = TelegramConnectRequest::query()->firstOrFail();

        $webhookResponse = $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
            ->postJson(route('api.telegram.webhook'), [
                'update_id' => 1001,
                'message' => [
                    'message_id' => 42,
                    'date' => now()->timestamp,
                    'chat' => [
                        'id' => 777001,
                        'type' => 'private',
                    ],
                    'from' => [
                        'id' => 777001,
                        'is_bot' => false,
                        'username' => 'demo_user',
                        'first_name' => 'Demo',
                        'last_name' => 'User',
                        'language_code' => 'uk',
                    ],
                    'text' => '/start link_'.$connectRequest->token,
                ],
            ]);

        $webhookResponse->assertOk();

        $this->assertDatabaseHas('telegram_user_links', [
            'user_id' => $user->id,
            'telegram_user_id' => '777001',
            'username' => 'demo_user',
        ]);

        $this->assertDatabaseHas('telegram_connect_requests', [
            'id' => $connectRequest->id,
            'status' => 'completed',
        ]);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '777001'
                && str_contains((string) $request['text'], 'Telegram connected');
        });
    }

    public function test_verify_links_recently_detected_group_without_fallback_prompt(): void
    {
        Http::fake();

        $user = User::factory()->create();

        TelegramUserLink::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => '555001',
            'username' => 'owner_user',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => '-100900100',
            'chat_type' => 'supergroup',
            'title' => 'Detected Group',
            'bot_membership_status' => 'member',
            'bot_is_member' => true,
            'added_by_telegram_user_id' => '555001',
            'discovered_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post(route('telegram.verify'));

        $response->assertRedirect('/dashboard');

        $chat->refresh();

        $this->assertSame($user->id, $chat->owner_user_id);
        $this->assertNotNull($chat->linked_at);

        Http::assertNothingSent();
    }

    public function test_verify_can_fallback_to_chat_shared_selection(): void
    {
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.webhook_secret', 'test-webhook-secret');
        Storage::fake('public');

        Http::fake([
            'https://api.telegram.org/bottest-bot-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                ],
            ]),
            'https://api.telegram.org/bottest-bot-token/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/shared-group.jpg',
                ],
            ]),
            'https://api.telegram.org/file/bottest-bot-token/photos/shared-group.jpg' => Http::response(
                'fake-image-binary',
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $user = User::factory()->create();

        $telegramUserLink = TelegramUserLink::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => '900001',
            'username' => 'fallback_user',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post(route('telegram.verify'));

        $response->assertRedirect('/dashboard');

        $chatRequest = TelegramChatRequest::query()->firstOrFail();
        $this->assertSame($user->id, $chatRequest->user_id);
        $this->assertSame($telegramUserLink->id, $chatRequest->telegram_user_link_id);

        Http::assertSent(function ($request) use ($telegramUserLink, $chatRequest): bool {
            $replyMarkup = json_decode((string) $request['reply_markup'], true);
            $requestChat = $replyMarkup['keyboard'][0][0]['request_chat'] ?? null;

            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === $telegramUserLink->telegram_user_id
                && is_array($requestChat)
                && (int) ($requestChat['request_id'] ?? 0) === $chatRequest->request_id
                && ($requestChat['chat_is_channel'] ?? null) === false
                && ($requestChat['request_photo'] ?? null) === true;
        });

        $webhookResponse = $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
            ->postJson(route('api.telegram.webhook'), [
                'update_id' => 1002,
                'message' => [
                    'message_id' => 43,
                    'date' => now()->timestamp,
                    'chat' => [
                        'id' => 900001,
                        'type' => 'private',
                    ],
                    'from' => [
                        'id' => 900001,
                        'is_bot' => false,
                        'username' => 'fallback_user',
                        'first_name' => 'Fallback',
                    ],
                    'chat_shared' => [
                        'request_id' => $chatRequest->request_id,
                        'chat_id' => '-100222333',
                        'title' => 'Shared Group',
                        'username' => 'shared_group',
                        'photo' => [
                            [
                                'file_id' => 'photo-small',
                                'file_unique_id' => 'unique-small',
                                'width' => 64,
                                'height' => 64,
                                'file_size' => 1200,
                            ],
                            [
                                'file_id' => 'photo-large',
                                'file_unique_id' => 'unique-large',
                                'width' => 256,
                                'height' => 256,
                                'file_size' => 4800,
                            ],
                        ],
                    ],
                ],
            ]);

        $webhookResponse->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'telegram_chat_id' => '-100222333',
            'owner_user_id' => $user->id,
            'title' => 'Shared Group',
            'username' => 'shared_group',
        ]);

        $this->assertDatabaseHas('telegram_chat_requests', [
            'id' => $chatRequest->id,
            'status' => 'completed',
        ]);

        $telegramChat = TelegramChat::query()
            ->where('telegram_chat_id', '-100222333')
            ->firstOrFail();

        $this->assertNotNull($telegramChat->avatar_path);
        Storage::disk('public')->assertExists($telegramChat->avatar_path);
    }

    public function test_verify_can_fallback_to_channel_selection(): void
    {
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.webhook_secret', 'test-webhook-secret');

        Http::fake([
            'https://api.telegram.org/bottest-bot-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                ],
            ]),
        ]);

        $user = User::factory()->create();

        $telegramUserLink = TelegramUserLink::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => '910001',
            'username' => 'channel_user',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from('/integrations')
            ->post(route('telegram.verify'), [
                'chat_kind' => 'channel',
            ]);

        $response->assertRedirect('/integrations');

        $chatRequest = TelegramChatRequest::query()->firstOrFail();

        Http::assertSent(function ($request) use ($telegramUserLink, $chatRequest): bool {
            $replyMarkup = json_decode((string) $request['reply_markup'], true);
            $requestChat = $replyMarkup['keyboard'][0][0]['request_chat'] ?? null;

            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === $telegramUserLink->telegram_user_id
                && is_array($requestChat)
                && (int) ($requestChat['request_id'] ?? 0) === $chatRequest->request_id
                && ($requestChat['chat_is_channel'] ?? null) === true
                && ($requestChat['request_photo'] ?? null) === true
                && ! array_key_exists('bot_is_member', $requestChat)
                && is_array($requestChat['user_administrator_rights'] ?? null)
                && ($requestChat['user_administrator_rights']['can_post_messages'] ?? null) === true
                && is_array($requestChat['bot_administrator_rights'] ?? null)
                && ($requestChat['bot_administrator_rights']['can_post_messages'] ?? null) === true;
        });

        $webhookResponse = $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
            ->postJson(route('api.telegram.webhook'), [
                'update_id' => 1003,
                'message' => [
                    'message_id' => 44,
                    'date' => now()->timestamp,
                    'chat' => [
                        'id' => 910001,
                        'type' => 'private',
                    ],
                    'from' => [
                        'id' => 910001,
                        'is_bot' => false,
                        'username' => 'channel_user',
                        'first_name' => 'Channel',
                    ],
                    'chat_shared' => [
                        'request_id' => $chatRequest->request_id,
                        'chat_id' => '-100333444',
                        'title' => 'Release Notes',
                        'username' => 'release_notes',
                    ],
                ],
            ]);

        $webhookResponse->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'telegram_chat_id' => '-100333444',
            'owner_user_id' => $user->id,
            'chat_type' => 'channel',
            'title' => 'Release Notes',
        ]);
    }

    public function test_subscription_can_use_verified_telegram_group_as_target(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Demo Feed</title>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $user = User::factory()->create();

        $telegramChat = TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-100444555',
            'chat_type' => 'supergroup',
            'title' => 'Delivery Group',
            'bot_membership_status' => 'member',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->post('/subscriptions', [
            'source_url' => 'https://example.com/feed.xml',
            'channel' => 'telegram',
            'telegram_chat_id' => $telegramChat->id,
            'polling_interval_minutes' => 30,
        ]);

        $response->assertRedirect('/dashboard');

        $subscription = Subscription::query()->firstOrFail();

        $this->assertSame($telegramChat->id, $subscription->telegram_chat_id);
        $this->assertSame($telegramChat->telegram_chat_id, $subscription->target);
    }

    public function test_subscription_defaults_to_only_verified_telegram_group(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response(
                <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Demo Feed</title>
  </channel>
</rss>
XML,
                200,
                ['Content-Type' => 'application/rss+xml; charset=utf-8']
            ),
        ]);

        $user = User::factory()->create();

        $telegramChat = TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-100777888',
            'chat_type' => 'supergroup',
            'title' => 'Default Delivery Group',
            'bot_membership_status' => 'member',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->post('/subscriptions', [
            'source_url' => 'https://example.com/feed.xml',
            'channel' => 'telegram',
            'polling_interval_minutes' => 30,
        ]);

        $response->assertRedirect('/dashboard');

        $subscription = Subscription::query()->firstOrFail();

        $this->assertSame($telegramChat->id, $subscription->telegram_chat_id);
        $this->assertSame($telegramChat->telegram_chat_id, $subscription->target);
    }
}
