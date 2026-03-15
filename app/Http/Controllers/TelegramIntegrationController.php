<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Services\TelegramLinkingService;
use App\Models\TelegramChat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TelegramIntegrationController extends Controller
{
    public function connect(Request $request, TelegramLinkingService $telegramLinkingService): RedirectResponse
    {
        try {
            $connectUrl = $telegramLinkingService->createConnectUrl($request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'telegram' => $exception->getMessage(),
            ]);
        }

        return redirect()->away($connectUrl);
    }

    public function verify(Request $request, TelegramLinkingService $telegramLinkingService): RedirectResponse
    {
        $validated = $request->validate([
            'chat_kind' => ['nullable', 'string', 'in:group,channel'],
        ]);

        $chatKind = (string) ($validated['chat_kind'] ?? 'group');
        $noun = $chatKind === 'channel' ? 'channel' : 'group';

        try {
            $linkedCount = $telegramLinkingService->claimDiscoveredChats($request->user(), $chatKind);

            if ($linkedCount > 0) {
                $label = $linkedCount === 1
                    ? $noun
                    : $noun.'s';

                return back()->with('status', 'Linked '.$linkedCount.' Telegram '.$label.'.');
            }

            $telegramLinkingService->sendChatSelectionPrompt($request->user(), $chatKind);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'telegram' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'status',
            'Add request sent to Telegram. Open the bot chat and choose your '.$noun.'.'
        );
    }

    public function destroy(
        Request $request,
        TelegramChat $telegramChat,
        TelegramLinkingService $telegramLinkingService,
    ): RedirectResponse {
        abort_unless($telegramChat->owner_user_id === $request->user()->id, 404);

        $chatName = $telegramChat->displayName();
        $chatLabel = strtolower($telegramChat->kindLabel());

        try {
            $removedFromTelegram = $telegramLinkingService->unlinkChat($request->user(), $telegramChat);
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'telegram' => $exception->getMessage(),
            ]);
        }

        $message = 'Removed Telegram '.$chatLabel.' "'.$chatName.'" from your workspace.';

        if (! $removedFromTelegram) {
            $message .= ' The link is gone here, but Telegram did not confirm the bot left, so you may want to remove it there too.';
        }

        return back()->with('status', $message);
    }
}
