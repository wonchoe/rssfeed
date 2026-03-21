<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Services\TelegramLinkingService;
use App\Models\User;
use App\Models\WebhookIntegration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public function index(Request $request, TelegramLinkingService $telegramLinkingService): View
    {
        return view('integrations.index', $this->buildViewData($request->user(), $telegramLinkingService));
    }

    public function status(Request $request, TelegramLinkingService $telegramLinkingService): JsonResponse
    {
        $viewData = $this->buildViewData($request->user(), $telegramLinkingService);

        return response()->json([
            'stats' => $viewData['stats'],
            'telegram' => [
                'connected' => $viewData['telegramUserLink'] !== null,
                'handle'    => $viewData['telegramUserLink']?->displayHandle() ?? '',
                'panel_html' => view('partials.telegram-linking-panel', $viewData)->render(),
            ],
        ]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string', 'in:slack,discord,teams,email'],
            'webhook_url' => ['required', 'string', 'max:2048'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $channel = $validated['channel'];
        $url = trim($validated['webhook_url']);

        $this->validateWebhookUrl($channel, $url);

        $urlHash = hash('sha256', Str::lower($url));

        $webhook = WebhookIntegration::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'channel' => $channel,
                'url_hash' => $urlHash,
            ],
            [
                'webhook_url' => $url,
                'label' => trim($validated['label'] ?? ''),
            ],
        );

        return response()->json([
            'ok' => true,
            'message' => ucfirst($channel) . ' webhook saved.',
            'webhook_id' => $webhook->id,
        ], $webhook->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyWebhook(Request $request, WebhookIntegration $webhookIntegration): JsonResponse
    {
        abort_unless($webhookIntegration->user_id === $request->user()->id, 403);

        $feedCount = $webhookIntegration->subscriptionCount();

        if ($feedCount > 0) {
            return response()->json([
                'ok' => false,
                'message' => "This webhook is still used by {$feedCount} " . Str::plural('feed', $feedCount) . '. Remove those subscriptions first.',
            ], 422);
        }

        $webhookIntegration->delete();

        return response()->json(['ok' => true, 'message' => 'Webhook removed.']);
    }

    private function validateWebhookUrl(string $channel, string $url): void
    {
        $error = match ($channel) {
            'slack' => ! str_starts_with($url, 'https://hooks.slack.com/')
                ? 'Slack webhook URLs must start with https://hooks.slack.com/'
                : null,
            'discord' => ! str_starts_with($url, 'https://discord.com/api/webhooks/')
                ? 'Discord webhook URLs must start with https://discord.com/api/webhooks/'
                : null,
            'teams' => ! (str_contains($url, '.logic.azure.com') || str_contains($url, '.office.com'))
                ? 'Teams webhook URLs must be Power Automate / Office workflow URLs.'
                : null,
            'email' => ! filter_var($url, FILTER_VALIDATE_EMAIL)
                ? 'Please enter a valid email address.'
                : null,
            default => 'Unsupported channel.',
        };

        if ($error !== null) {
            throw ValidationException::withMessages([
                'webhook_url' => $error,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(User $user, TelegramLinkingService $telegramLinkingService): array
    {
        $telegramUserLink = $user->telegramUserLink;
        $telegramGroupChats = $telegramLinkingService->linkedChatsForKind($user, 'group');
        $telegramChannelChats = $telegramLinkingService->linkedChatsForKind($user, 'channel');
        $allTelegramChats = $telegramGroupChats
            ->concat($telegramChannelChats)
            ->sortBy(fn ($chat) => $chat->displayName())
            ->values();

        $allWebhooks = WebhookIntegration::query()
            ->where('user_id', $user->id)
            ->orderBy('channel')
            ->orderByDesc('created_at')
            ->get();

        $webhooksByChannel = $allWebhooks->groupBy('channel');

        $mapWebhooks = static fn (string $ch) => ($webhooksByChannel->get($ch) ?? collect())->map(fn ($w) => [
            'id' => $w->id,
            'target' => $w->webhook_url,
            'label' => $w->label,
            'feed_count' => $w->subscriptionCount(),
        ]);

        $webhookCounts = collect(['slack', 'discord', 'teams'])->mapWithKeys(
            fn (string $ch) => [$ch => ($webhooksByChannel->get($ch) ?? collect())->count()]
        );
        $emailCount = ($webhooksByChannel->get('email') ?? collect())->count();

        $activeProviders = ($telegramUserLink !== null ? 1 : 0)
            + ($webhookCounts->get('slack', 0) > 0 ? 1 : 0)
            + ($webhookCounts->get('discord', 0) > 0 ? 1 : 0)
            + ($webhookCounts->get('teams', 0) > 0 ? 1 : 0)
            + ($emailCount > 0 ? 1 : 0);

        return [
            'telegramUserLink' => $telegramUserLink,
            'telegramChats' => $allTelegramChats,
            'telegramGroupChats' => $telegramGroupChats,
            'telegramChannelChats' => $telegramChannelChats,
            'telegramBotUsername' => (string) config('services.telegram.bot_username'),
            'webhookCounts' => $webhookCounts,
            'emailCount' => $emailCount,
            'slackWebhooks' => $mapWebhooks('slack'),
            'discordWebhooks' => $mapWebhooks('discord'),
            'teamsWebhooks' => $mapWebhooks('teams'),
            'emailWebhooks' => $mapWebhooks('email'),
            'stats' => [
                'activeProviders' => $activeProviders,
                'linkedGroups' => $telegramGroupChats->count(),
                'linkedChannels' => $telegramChannelChats->count(),
                'readyDestinations' => $allTelegramChats->count() + $webhookCounts->sum() + $emailCount,
            ],
        ];
    }
}
