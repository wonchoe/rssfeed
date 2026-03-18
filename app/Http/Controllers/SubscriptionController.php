<?php

namespace App\Http\Controllers;

use App\Events\SourceCreated;
use App\Jobs\DiscoverSourceTypeJob;
use App\Jobs\FetchSourceJob;
use App\Jobs\SendTelegramMessageJob;
use App\Jobs\TranslateArticleJob;
use App\Models\Article;
use App\Models\Delivery;
use App\Models\Subscription;
use App\Models\TelegramChat;
use App\Support\HorizonRuntime;
use App\Support\PipelineStage;
use App\Support\SourceCatalog;
use App\Support\SourceUsageStateManager;
use App\Support\UrlNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function store(
        Request $request,
        SourceCatalog $sourceCatalog,
        SourceUsageStateManager $usageStateManager,
    ): RedirectResponse {
        $validated = $request->validate([
            'source_url' => [
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $normalized = UrlNormalizer::normalize((string) $value);

                    if (! UrlNormalizer::isValidHttpUrl($normalized)) {
                        $fail('Please enter a valid HTTP/HTTPS URL.');
                    }
                },
            ],
            'channel' => ['required', 'string', 'in:telegram,slack,discord,email,webhook'],
            'target' => ['nullable', 'string', 'max:512'],
            'telegram_chat_id' => ['nullable', 'integer'],
            'polling_interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'translate_enabled' => ['nullable', 'boolean'],
            'translate_language' => ['nullable', 'string', 'max:10', 'in:uk,en,es,fr,de,it,pt,ja,ko,zh,ar,hi,pl,nl,tr,ru,sv,da,fi,no,cs,ro,hu,el,th,vi,id,ms,he,bg'],
            'created_from' => ['nullable', 'string', 'max:64'],
            'redirect_to' => ['nullable', 'string', 'max:255'],
        ]);

        $submittedSourceUrl = trim((string) $validated['source_url']);
        $normalizedSourceUrl = $sourceCatalog->normalizeSubmittedUrl($submittedSourceUrl);
        $source = $sourceCatalog->findOrCreate(
            url: $normalizedSourceUrl,
            pollingIntervalMinutes: (int) $validated['polling_interval_minutes'],
        );
        $sourceCatalog->attachAlias($source, $submittedSourceUrl);
        $sourceCatalog->updateSourceHostMeta($source, $normalizedSourceUrl);

        if ($source->polling_interval_minutes > (int) $validated['polling_interval_minutes']) {
            $source->update([
                'polling_interval_minutes' => (int) $validated['polling_interval_minutes'],
            ]);
        }

        $selectedTelegramChat = null;

        if (
            $validated['channel'] === 'telegram' &&
            filled($validated['telegram_chat_id'] ?? null)
        ) {
            $selectedTelegramChat = TelegramChat::query()
                ->whereKey((int) $validated['telegram_chat_id'])
                ->where('owner_user_id', $request->user()->id)
                ->where('bot_is_member', true)
                ->first();

            if ($selectedTelegramChat === null) {
                throw ValidationException::withMessages([
                    'telegram_chat_id' => 'Please choose a Telegram group linked to your account.',
                ]);
            }
        }

        if ($validated['channel'] === 'telegram' && $selectedTelegramChat === null) {
            $availableTelegramChats = TelegramChat::query()
                ->where('owner_user_id', $request->user()->id)
                ->where('bot_is_member', true)
                ->orderByDesc('linked_at')
                ->limit(2)
                ->get();

            if ($availableTelegramChats->count() === 1) {
                $selectedTelegramChat = $availableTelegramChats->first();
            }
        }

        $normalizedTarget = $selectedTelegramChat?->telegram_chat_id
            ?? trim((string) ($validated['target'] ?? ''));

        if ($normalizedTarget === '') {
            throw ValidationException::withMessages([
                'target' => 'Please provide a delivery target.',
            ]);
        }

        $targetHash = hash('sha256', Str::lower($normalizedTarget));

        $subscription = Subscription::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'source_id' => $source->id,
                'channel' => $validated['channel'],
                'target_hash' => $targetHash,
            ],
            [
                'telegram_chat_id' => $selectedTelegramChat?->id,
                'target' => $normalizedTarget,
                'is_active' => true,
                'translate_enabled' => (bool) ($validated['translate_enabled'] ?? false),
                'translate_language' => ($validated['translate_enabled'] ?? false) ? ($validated['translate_language'] ?? null) : null,
                'config' => array_filter([
                    'created_from' => $validated['created_from'] ?? 'dashboard',
                    'telegram_chat_title' => $selectedTelegramChat?->displayName(),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ]
        );

        $usageStateManager->refresh($source, forceImmediateCheck: true);
        $initialDeliveryState = $this->queueInitialTelegramSampleDelivery($subscription);

        if ($source->wasRecentlyCreated) {
            SourceCreated::dispatch(
                sourceId: (string) $source->id,
                sourceUrl: $source->source_url,
                context: [
                    'trigger' => 'dashboard_subscription_create',
                    'user_id' => $request->user()->id,
                ],
            );
        } else {
            $context = [
                'trigger' => 'dashboard_subscription_refresh',
                'user_id' => $request->user()->id,
            ];

            $needsDiscovery = in_array($source->source_type, ['unknown', 'html'], true)
                || in_array($source->status, ['pending', 'discovery_failed', 'parse_failed', 'normalize_failed', 'detect_failed'], true)
                || $source->canonical_url === null;

            if ($needsDiscovery) {
                DiscoverSourceTypeJob::dispatch((string) $source->id, $context)->onQueue('ingestion');
            } else {
                FetchSourceJob::dispatch((string) $source->id, $context)->onQueue('ingestion');
            }
        }

        $message = $subscription->wasRecentlyCreated
            ? 'Subscription created successfully.'
            : 'Subscription already existed, and was re-enabled.';

        if ($initialDeliveryState === 'sent') {
            $message .= ' Sent the latest article to Telegram as a delivery check.';
        } elseif ($initialDeliveryState === 'queued') {
            $message .= ' Queued the latest article for Telegram as a delivery check.';
        }

        if (app(HorizonRuntime::class)->hasActiveSupervisors() === false) {
            $message .= ' Queue workers are offline, so ingestion is paused until Horizon is started.';
        }

        $redirectTo = trim((string) ($validated['redirect_to'] ?? ''));

        if (
            $redirectTo !== '' &&
            str_starts_with($redirectTo, '/') &&
            ! str_starts_with($redirectTo, '//')
        ) {
            return redirect($redirectTo)->with('status', $message);
        }

        return redirect()->route('dashboard')->with('status', $message);
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->ensureOwnership($request, $subscription);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $subscription->update([
            'is_active' => (bool) $validated['is_active'],
        ]);

        app(SourceUsageStateManager::class)->refresh(
            $subscription->source()->firstOrFail(),
            forceImmediateCheck: (bool) $validated['is_active'],
        );

        return redirect()->route('dashboard')->with('status', 'Subscription updated.');
    }

    public function destroy(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->ensureOwnership($request, $subscription);

        $source = $subscription->source()->first();
        $subscription->delete();

        if ($source !== null) {
            app(SourceUsageStateManager::class)->refresh($source);
        }

        return redirect()->route('dashboard')->with('status', 'Subscription deleted.');
    }

    private function ensureOwnership(Request $request, Subscription $subscription): void
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);
    }

    private function queueInitialTelegramSampleDelivery(Subscription $subscription): string
    {
        if (
            ! $subscription->wasRecentlyCreated ||
            $subscription->channel !== 'telegram' ||
            ! $subscription->is_active
        ) {
            return 'none';
        }

        /** @var Article|null $article */
        $article = Article::query()
            ->where('source_id', $subscription->source_id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if ($article === null) {
            return 'none';
        }

        $delivery = Delivery::query()->firstOrCreate(
            [
                'article_id' => $article->id,
                'subscription_id' => $subscription->id,
                'channel' => 'telegram',
            ],
            [
                'status' => 'queued',
                'attempts' => 0,
                'queued_at' => now(),
                'meta' => [
                    'pipeline_stage' => PipelineStage::Deliver->value,
                    'trigger' => 'initial_subscription_sample',
                    'sample_delivery' => true,
                ],
            ]
        );

        if (! $delivery->wasRecentlyCreated && $delivery->status === 'delivered') {
            return 'none';
        }

        if (! $delivery->wasRecentlyCreated) {
            $delivery->update([
                'status' => 'queued',
                'queued_at' => now(),
                'meta' => array_merge((array) ($delivery->meta ?? []), [
                    'pipeline_stage' => PipelineStage::Deliver->value,
                    'trigger' => 'initial_subscription_sample',
                    'sample_delivery' => true,
                ]),
            ]);
        }

        if ($subscription->translate_enabled && $subscription->translate_language) {
            TranslateArticleJob::dispatch(
                articleId: $article->id,
                language: $subscription->translate_language,
                recipients: [
                    [
                        'subscription_id' => $subscription->id,
                        'delivery_id' => $delivery->id,
                    ],
                ],
            )->onQueue('translation');

            return 'queued';
        }

        $dispatch = static fn () => SendTelegramMessageJob::dispatch(
            subscriptionId: (string) $subscription->id,
            articleUrl: $article->canonical_url,
            message: $article->title,
            context: [
                'delivery_id' => $delivery->id,
                'article_id' => $article->id,
                'source_id' => $subscription->source_id,
                'pipeline_stage' => PipelineStage::Deliver->value,
                'trigger' => 'initial_subscription_sample',
                'sample_delivery' => true,
            ],
        )->onQueue('delivery');

        if (app(HorizonRuntime::class)->shouldRunSyncFallback()) {
            SendTelegramMessageJob::dispatchSync(
                subscriptionId: (string) $subscription->id,
                articleUrl: $article->canonical_url,
                message: $article->title,
                context: [
                    'delivery_id' => $delivery->id,
                    'article_id' => $article->id,
                    'source_id' => $subscription->source_id,
                    'pipeline_stage' => PipelineStage::Deliver->value,
                    'trigger' => 'initial_subscription_sample',
                    'sample_delivery' => true,
                ],
            );

            return 'sent';
        }

        $dispatch();

        return 'queued';
    }
}
