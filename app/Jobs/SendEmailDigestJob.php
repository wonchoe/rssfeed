<?php

namespace App\Jobs;

use App\Domain\Delivery\Contracts\EmailDigestDeliveryService;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendEmailDigestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct() {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(EmailDigestDeliveryService $digestService): void
    {
        $subscriptions = Subscription::query()
            ->where('channel', 'email')
            ->where('is_active', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $sent = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $digestService->sendDigest($subscription->id);
                $sent++;
            } catch (\Throwable $e) {
                Log::error('EmailDigest: failed to send digest', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('EmailDigest: daily run completed', [
            'total_subscriptions' => $subscriptions->count(),
            'sent' => $sent,
        ]);
    }
}
