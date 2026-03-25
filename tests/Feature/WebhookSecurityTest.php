<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_new_teams_power_platform_webhook_encrypted(): void
    {
        $user = User::factory()->create();
        $url = 'https://default55e374bf374e49dea716836ce6f714.d1.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/ddcaf355b2ab4867a471dbd01ecace85/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=test-signature';

        $response = $this->actingAs($user)->postJson(route('integrations.webhooks.store'), [
            'channel' => 'teams',
            'webhook_url' => $url,
            'label' => 'Engineering Team',
        ]);

        $response->assertCreated();

        $storedWebhook = DB::table('webhook_integrations')->first();

        $this->assertNotNull($storedWebhook);
        $this->assertNotSame($url, $storedWebhook->webhook_url);
        $this->assertSame(hash('sha256', strtolower($url)), (string) $storedWebhook->url_hash);
        $this->assertSame($url, WebhookIntegration::query()->firstOrFail()->webhook_url);
    }

    public function test_integrations_page_does_not_show_saved_webhook_url(): void
    {
        $user = User::factory()->create();
        $url = 'https://default55e374bf374e49dea716836ce6f714.d1.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/ddcaf355b2ab4867a471dbd01ecace85/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=test-signature';

        WebhookIntegration::query()->create([
            'user_id' => $user->id,
            'channel' => 'teams',
            'webhook_url' => $url,
            'url_hash' => hash('sha256', strtolower($url)),
            'label' => 'Engineering Team',
        ]);

        $response = $this->actingAs($user)->get(route('integrations.index'));

        $response->assertOk();
        $response->assertSee('Engineering Team');
        $response->assertSee('URL hidden for security.');
        $response->assertDontSee($url);
    }

    public function test_subscription_stores_webhook_target_encrypted_from_saved_integration(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $sourceUrl = 'https://example.com/feed.xml';
        $webhookUrl = 'https://default55e374bf374e49dea716836ce6f714.d1.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/ddcaf355b2ab4867a471dbd01ecace85/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=test-signature';

        Source::query()->create([
            'source_url_hash' => hash('sha256', $sourceUrl),
            'source_url' => $sourceUrl,
            'canonical_url_hash' => hash('sha256', $sourceUrl),
            'canonical_url' => $sourceUrl,
            'source_type' => 'rss',
            'status' => 'active',
            'polling_interval_minutes' => 30,
        ]);

        $webhookIntegration = WebhookIntegration::query()->create([
            'user_id' => $user->id,
            'channel' => 'teams',
            'webhook_url' => $webhookUrl,
            'url_hash' => hash('sha256', strtolower($webhookUrl)),
            'label' => 'Engineering Team',
        ]);

        $response = $this->actingAs($user)->post('/subscriptions', [
            'source_url' => $sourceUrl,
            'channel' => 'teams',
            'webhook_integration_id' => $webhookIntegration->id,
            'polling_interval_minutes' => 30,
        ]);

        $response->assertRedirect('/dashboard');

        $subscription = Subscription::query()->firstOrFail();
        $rawSubscription = DB::table('subscriptions')->first();

        $this->assertNotSame($webhookUrl, $rawSubscription->target);
        $this->assertSame(hash('sha256', strtolower($webhookUrl)), $subscription->target_hash);
        $this->assertSame($webhookUrl, $subscription->target);
        $this->assertSame('Engineering Team', data_get($subscription->config, 'webhook_label'));
    }
}