<?php

namespace App\Providers;

use App\Jobs\FetchSourceJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('feed-generate', function (Request $request): Limit {
            return Limit::perMinute((int) env('RATE_LIMIT_FEED_GENERATE', 40))
                ->by($this->rateLimitKey($request))
                ->response(fn (Request $request, array $headers): Response => $this->tooManyAttemptsResponse(
                    $request,
                    $headers,
                    'Too many generation requests. Please try again in a moment.'
                ));
        });

        RateLimiter::for('feed-status', function (Request $request): Limit {
            return Limit::perMinute((int) env('RATE_LIMIT_FEED_STATUS', 600))
                ->by($this->rateLimitKey($request))
                ->response(fn (Request $request, array $headers): Response => $this->tooManyAttemptsResponse(
                    $request,
                    $headers,
                    'Status polling limit reached. Please wait a few seconds.'
                ));
        });

        RateLimiter::for('feed-stream', function (Request $request): Limit {
            return Limit::perMinute((int) env('RATE_LIMIT_FEED_STREAM', 240))
                ->by($this->rateLimitKey($request))
                ->response(fn (Request $request, array $headers): Response => $this->tooManyAttemptsResponse(
                    $request,
                    $headers,
                    'Stream reconnect limit reached. Please wait before retrying.'
                ));
        });

        RateLimiter::for('feed-profile-stream', function (Request $request): Limit {
            return Limit::perMinute((int) env('RATE_LIMIT_FEED_PROFILE_STREAM', 240))
                ->by($this->rateLimitKey($request))
                ->response(fn (Request $request, array $headers): Response => $this->tooManyAttemptsResponse(
                    $request,
                    $headers,
                    'Feed profile stream limit reached. Please wait before reconnecting.'
                ));
        });

        RateLimiter::for('fetch-domain', function (object $job): Limit {
            $domainKey = $job instanceof FetchSourceJob
                ? $job->fetchDomainKey()
                : 'unknown';

            return Limit::perMinute((int) env('INGESTION_FETCH_MAX_PER_DOMAIN_PER_MINUTE', 12))
                ->by('fetch-domain:'.$domainKey);
        });

        if ($this->app->environment('local') && config('app.url')) {
            URL::forceRootUrl((string) config('app.url'));
        }
    }

    private function rateLimitKey(Request $request): string
    {
        $user = $request->user();

        if ($user !== null) {
            return 'user:'.$user->id;
        }

        return 'ip:'.$request->ip();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function tooManyAttemptsResponse(Request $request, array $headers, string $message): Response
    {
        $retryAfter = isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null;

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
                'error' => 'too_many_attempts',
                'retry_after_seconds' => $retryAfter,
            ], 429, $headers);
        }

        return response($message, 429, $headers);
    }
}
