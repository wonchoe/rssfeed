<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\Subscription;
use App\Models\TelegramChat;
use App\Models\TelegramUserLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrations_page_shows_empty_telegram_state(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('integrations.index'));

        $response->assertOk();
        $response->assertSee('Integrations');
        $response->assertSee('Telegram');
        $response->assertSee('Not Connected');
    }

    public function test_integrations_page_lists_linked_telegram_groups(): void
    {
        $user = User::factory()->create();

        TelegramUserLink::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => '12001',
            'username' => 'linked_user',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-100555666',
            'chat_type' => 'supergroup',
            'title' => 'Product Updates',
            'bot_membership_status' => 'member',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-100777999',
            'chat_type' => 'channel',
            'title' => 'Launch Broadcasts',
            'bot_membership_status' => 'administrator',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('integrations.index'));

        $response->assertOk();
        $response->assertSee('Connected');
        $response->assertSee('Product Updates');
        $response->assertSee('Launch Broadcasts');
        $response->assertSee('Linked Groups');
        $response->assertSee('Linked Channels');
        $response->assertSee('Remove');
    }

    public function test_integrations_status_returns_latest_telegram_panel_data(): void
    {
        $user = User::factory()->create();

        TelegramUserLink::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => '22001',
            'username' => 'status_user',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-100101010',
            'chat_type' => 'supergroup',
            'title' => 'Realtime Group',
            'bot_membership_status' => 'member',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('integrations.status'));

        $response->assertOk();
        $response->assertJsonPath('stats.activeProviders', 1);
        $response->assertJsonPath('stats.linkedGroups', 1);
        $response->assertJsonPath('stats.linkedChannels', 0);
        $response->assertJsonPath('stats.readyDestinations', 1);
        $response->assertJsonPath('telegram.connected', true);
        $this->assertStringContainsString('Realtime Group', (string) $response->json('telegram.panel_html'));
    }

    public function test_user_can_remove_linked_telegram_destination(): void
    {
        config()->set('services.telegram.bot_token', 'test-bot-token');

        Http::fake([
            'https://api.telegram.org/bottest-bot-token/leaveChat' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $user = User::factory()->create();

        $telegramChat = TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-100202020',
            'chat_type' => 'channel',
            'title' => 'Ship Notes',
            'bot_membership_status' => 'administrator',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->from('/integrations')
            ->delete(route('telegram.chats.destroy', $telegramChat));

        $response->assertRedirect('/integrations');
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('telegram_chats', [
            'id' => $telegramChat->id,
            'owner_user_id' => null,
            'bot_is_member' => false,
            'bot_membership_status' => 'left',
        ]);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/leaveChat')
                && $request['chat_id'] === '-100202020';
        });
    }

    public function test_user_cannot_remove_destination_that_is_used_by_subscription(): void
    {
        Http::fake();

        $user = User::factory()->create();

        $telegramChat = TelegramChat::query()->create([
            'owner_user_id' => $user->id,
            'telegram_chat_id' => '-100303030',
            'chat_type' => 'supergroup',
            'title' => 'Protected Group',
            'bot_membership_status' => 'member',
            'bot_is_member' => true,
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);

        $sourceUrl = 'https://example.com/feed.xml';
        $source = Source::query()->create([
            'source_url_hash' => hash('sha256', $sourceUrl),
            'source_url' => $sourceUrl,
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'source_id' => $source->id,
            'telegram_chat_id' => $telegramChat->id,
            'channel' => 'telegram',
            'target_hash' => hash('sha256', strtolower($telegramChat->telegram_chat_id)),
            'target' => $telegramChat->telegram_chat_id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->from('/integrations')
            ->delete(route('telegram.chats.destroy', $telegramChat));

        $response->assertRedirect('/integrations');
        $response->assertSessionHasErrors(['telegram']);

        $this->assertDatabaseHas('telegram_chats', [
            'id' => $telegramChat->id,
            'owner_user_id' => $user->id,
            'bot_is_member' => true,
        ]);

        Http::assertNothingSent();
    }
}
