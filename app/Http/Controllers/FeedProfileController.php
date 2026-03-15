<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedProfileController extends Controller
{
    public function show(Request $request, Source $source): View
    {
        $this->authorizeSourceAccess($request, $source);
        $user = $request->user();

        $userSubscriptions = $source->subscriptions()
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();

        $recentArticles = $source->articles()
            ->latest('published_at')
            ->latest('id')
            ->limit(25)
            ->get();

        $recentFetches = $source->fetches()
            ->latest('fetched_at')
            ->limit(20)
            ->get();

        return view('feeds.show', [
            'source' => $source,
            'userSubscriptions' => $userSubscriptions,
            'recentArticles' => $recentArticles,
            'recentFetches' => $recentFetches,
            'stats' => [
                'articles' => $source->articles()->count(),
                'activeSubscriptions' => $source->subscriptions()->where('is_active', true)->count(),
                'fetches' => $source->fetches()->count(),
            ],
        ]);
    }

    public function stream(Request $request, Source $source): StreamedResponse
    {
        $this->authorizeSourceAccess($request, $source);

        return response()->stream(function () use ($source): void {
            ignore_user_abort(true);

            $startedAt = time();
            $lastPayload = null;

            echo "retry: 2000\n\n";
            @ob_flush();
            @flush();

            while (! connection_aborted()) {
                $source->refresh();
                $payload = $this->streamPayload($source);
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

                if (app()->environment('testing')) {
                    break;
                }

                if ((time() - $startedAt) >= 90) {
                    break;
                }

                sleep(2);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function authorizeSourceAccess(Request $request, Source $source): void
    {
        $user = $request->user();

        $hasAccess = $source->subscriptions()
            ->where('user_id', $user->id)
            ->exists();

        abort_unless($hasAccess, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function streamPayload(Source $source): array
    {
        $latestArticle = $source->articles()
            ->latest('published_at')
            ->latest('id')
            ->first();

        $latestFetch = $source->fetches()
            ->latest('fetched_at')
            ->first();

        return [
            'ok' => true,
            'source' => [
                'id' => $source->id,
                'status' => $source->status,
                'source_type' => $source->source_type,
                'last_fetched_at' => $source->last_fetched_at?->toIso8601String(),
                'last_parsed_at' => $source->last_parsed_at?->toIso8601String(),
            ],
            'stats' => [
                'articles' => $source->articles()->count(),
                'active_subscriptions' => $source->subscriptions()->where('is_active', true)->count(),
                'fetches' => $source->fetches()->count(),
            ],
            'latest_fetch' => $latestFetch === null ? null : [
                'http_status' => $latestFetch->http_status,
                'fetched_at' => $latestFetch->fetched_at?->toIso8601String(),
                'payload_bytes' => $latestFetch->payload_bytes,
            ],
            'latest_article' => $latestArticle === null ? null : [
                'title' => $latestArticle->title,
                'canonical_url' => $latestArticle->canonical_url,
                'published_at' => $latestArticle->published_at?->toIso8601String(),
            ],
        ];
    }
}
