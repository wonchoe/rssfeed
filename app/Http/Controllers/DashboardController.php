<?php

namespace App\Http\Controllers;

use App\Models\WebhookIntegration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $subscriptionsQuery = $user->subscriptions()->with(['source', 'telegramChat'])->latest('id');
        $telegramGroupChats = $user->telegramChats()
            ->where('bot_is_member', true)
            ->whereIn('chat_type', ['group', 'supergroup'])
            ->orderByDesc('linked_at')
            ->orderBy('title')
            ->get();
        $telegramChannelChats = $user->telegramChats()
            ->where('bot_is_member', true)
            ->where('chat_type', 'channel')
            ->orderByDesc('linked_at')
            ->orderBy('title')
            ->get();

        return view('dashboard.index', [
            'subscriptions' => $subscriptionsQuery->paginate(12),
            'stats' => [
                'subscriptions' => (clone $subscriptionsQuery)->count(),
                'activeSubscriptions' => $user->subscriptions()->where('is_active', true)->count(),
                'sources' => $user->subscriptions()->distinct('source_id')->count('source_id'),
            ],
            'telegramGroupChats' => $telegramGroupChats,
            'telegramChannelChats' => $telegramChannelChats,
            'webhookIntegrations' => WebhookIntegration::query()
                ->where('user_id', $user->id)
                ->orderBy('channel')
                ->orderBy('label')
                ->get(),
        ]);
    }
}
