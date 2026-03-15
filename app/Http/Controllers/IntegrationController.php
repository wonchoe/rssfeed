<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Services\TelegramLinkingService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'panel_html' => view('partials.telegram-linking-panel', $viewData)->render(),
            ],
        ]);
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

        return [
            'telegramUserLink' => $telegramUserLink,
            'telegramChats' => $allTelegramChats,
            'telegramGroupChats' => $telegramGroupChats,
            'telegramChannelChats' => $telegramChannelChats,
            'telegramBotUsername' => (string) config('services.telegram.bot_username'),
            'stats' => [
                'activeProviders' => $telegramUserLink !== null ? 1 : 0,
                'linkedGroups' => $telegramGroupChats->count(),
                'linkedChannels' => $telegramChannelChats->count(),
                'readyDestinations' => $allTelegramChats->count(),
            ],
        ];
    }
}
