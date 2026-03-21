<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateFeedPreviewJob;
use App\Models\FeedGeneration;
use App\Models\WebhookIntegration;
use App\Support\AdminAccess;
use App\Support\FeedGenerationWatchdog;
use App\Support\HorizonRuntime;
use App\Support\UrlNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FeedGeneratorController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('feeds.generator', [
            'telegramGroupChats' => $user->telegramChats()
                ->where('bot_is_member', true)
                ->whereIn('chat_type', ['group', 'supergroup'])
                ->orderByDesc('linked_at')
                ->orderBy('title')
                ->get(),
            'telegramChannelChats' => $user->telegramChats()
                ->where('bot_is_member', true)
                ->where('chat_type', 'channel')
                ->orderByDesc('linked_at')
                ->orderBy('title')
                ->get(),
            'webhookIntegrations' => WebhookIntegration::query()
                ->where('user_id', $user->id)
                ->orderBy('channel')
                ->orderBy('label')
                ->get(),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
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
        ]);

        $requestedUrl = UrlNormalizer::normalize($validated['source_url']);
        $runToken = (string) Str::uuid();

        $generation = FeedGeneration::query()->create([
            'user_id' => $request->user()->id,
            'requested_url' => $requestedUrl,
            'status' => 'queued',
            'message' => 'Queued for generation.',
            'meta' => [
                'run_token' => $runToken,
            ],
        ]);

        GenerateFeedPreviewJob::dispatch((string) $generation->id, $runToken)->onQueue('ingestion');

        if (app(HorizonRuntime::class)->shouldRunSyncFallback()) {
            try {
                GenerateFeedPreviewJob::dispatchSync((string) $generation->id, $runToken);
            } catch (Throwable) {
                // Job itself stores failed status/message on generation.
            }
        }

        $generation->refresh();

        return response()->json([
            'ok' => true,
            'generation_id' => $generation->id,
            'status' => $generation->status,
            'message' => $generation->message,
        ]);
    }

    public function status(Request $request, FeedGeneration $feedGeneration): JsonResponse
    {
        abort_unless($feedGeneration->user_id === $request->user()->id, 403);
        $this->syncTimedOutGeneration($feedGeneration);

        return response()->json($this->statusPayload(
            $feedGeneration,
            app(AdminAccess::class)->canAccess($request->user()),
        ));
    }

    public function stream(Request $request, FeedGeneration $feedGeneration): StreamedResponse
    {
        abort_unless($feedGeneration->user_id === $request->user()->id, 403);
        $canSeeAdminDebug = app(AdminAccess::class)->canAccess($request->user());

        return response()->stream(function () use ($feedGeneration, $canSeeAdminDebug): void {
            ignore_user_abort(true);

            $startedAt = time();
            $lastPayload = null;

            echo "retry: 1000\n\n";
            @ob_flush();
            @flush();

            while (! connection_aborted()) {
                $this->syncTimedOutGeneration($feedGeneration);
                $feedGeneration->refresh();
                $payload = $this->statusPayload($feedGeneration, $canSeeAdminDebug);
                $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

                if ($encoded !== false && $encoded !== $lastPayload) {
                    echo "event: status\n";
                    echo 'data: '.$encoded."\n\n";

                    $lastPayload = $encoded;
                    @ob_flush();
                    @flush();
                } else {
                    echo ": keepalive\n\n";
                    @ob_flush();
                    @flush();
                }

                if (in_array($feedGeneration->status, ['ready', 'failed'], true)) {
                    if ($encoded !== false) {
                        echo "event: done\n";
                        echo 'data: '.$encoded."\n\n";
                        @ob_flush();
                        @flush();
                    }

                    break;
                }

                // Recycle long-lived connection so browser can reconnect cleanly.
                if ((time() - $startedAt) >= 65) {
                    break;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(FeedGeneration $feedGeneration, bool $canSeeAdminDebug = false): array
    {
        $meta = (array) ($feedGeneration->meta ?? []);
        $isTimedOut = (bool) ($meta['timed_out'] ?? false);
        $previewItems = array_values(array_map(
            fn (mixed $item): array => $this->sanitizePreviewItem($item),
            is_array($feedGeneration->preview_items) ? $feedGeneration->preview_items : [],
        ));

        return [
            'ok' => true,
            'generation_id' => $feedGeneration->id,
            'status' => $feedGeneration->status,
            'message' => $feedGeneration->message,
            'source' => [
                'requested_url' => $feedGeneration->requested_url,
                'resolved_url' => $feedGeneration->resolved_url,
                'source_type' => $feedGeneration->source_type,
                'warnings' => $meta['warnings'] ?? [],
                'confidence' => $meta['confidence'] ?? null,
                'status_code' => $meta['status_code'] ?? null,
                'source_id' => $feedGeneration->source_id,
                'cache_mode' => $meta['cache_mode'] ?? null,
                'last_success_at' => $meta['last_success_at'] ?? null,
                'ai_preview' => $meta['ai_preview'] ?? null,
            ],
            'preview' => $previewItems,
            'debug' => [
                'timed_out' => $isTimedOut,
                'escalated' => (bool) ($meta['escalated_to_admin_debug'] ?? false),
                'admin_url' => $canSeeAdminDebug ? route('admin.debug.generations') : null,
            ],
            'created_at' => $feedGeneration->created_at?->toIso8601String(),
            'updated_at' => $feedGeneration->updated_at?->toIso8601String(),
        ];
    }

    private function syncTimedOutGeneration(FeedGeneration $feedGeneration): void
    {
        app(FeedGenerationWatchdog::class)->markTimedOut($feedGeneration);
    }

    /**
     * @return array{title:string,url:string,summary:?string,image_url:?string,published_at:?string}
     */
    private function sanitizePreviewItem(mixed $item): array
    {
        $payload = is_array($item) ? $item : [];

        $title = $this->sanitizeText($payload['title'] ?? null, 240) ?? 'Untitled';
        $rawUrl = (string) ($payload['url'] ?? '');
        $normalizedUrl = UrlNormalizer::normalize($rawUrl);
        $url = UrlNormalizer::isValidHttpUrl($normalizedUrl) ? $normalizedUrl : $rawUrl;
        $summary = $this->sanitizeText($payload['summary'] ?? null, 420);
        $image = trim((string) ($payload['image_url'] ?? $payload['image'] ?? ''));
        $publishedAt = $this->normalizePreviewDate($payload['published_at'] ?? null);

        return [
            'title' => $title,
            'url' => trim($url) !== '' ? $url : '#',
            'summary' => $summary,
            'image_url' => $image !== '' ? $image : null,
            'published_at' => $publishedAt,
        ];
    }

    private function sanitizeText(mixed $value, int $limit = 420): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = trim(strip_tags($decoded));
        $clean = preg_replace('/\s+/u', ' ', $stripped) ?: '';

        if ($clean === '') {
            return null;
        }

        return Str::limit($clean, max(1, $limit), '...');
    }

    private function normalizePreviewDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
