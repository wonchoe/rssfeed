<?php

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Contracts\EmailDigestDeliveryService;
use App\Mail\DailyDigestMail;
use App\Models\Article;
use App\Models\Delivery;
use App\Models\Subscription;
use App\Support\PipelineStage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DefaultEmailDigestDeliveryService implements EmailDigestDeliveryService
{
    public function sendDigest(int $subscriptionId): void
    {
        $subscription = Subscription::query()
            ->with('source')
            ->find($subscriptionId);

        if ($subscription === null || ! $subscription->is_active || $subscription->channel !== 'email') {
            return;
        }

        $emailTarget = $subscription->target;

        if ($emailTarget === '' || ! filter_var($emailTarget, FILTER_VALIDATE_EMAIL)) {
            Log::warning('EmailDigest: invalid email target', [
                'subscription_id' => $subscriptionId,
                'target' => $emailTarget,
            ]);

            return;
        }

        // Find articles not yet delivered for this subscription
        $deliveredArticleIds = Delivery::query()
            ->where('subscription_id', $subscriptionId)
            ->where('channel', 'email')
            ->where('status', 'delivered')
            ->pluck('article_id')
            ->all();

        $articles = Article::query()
            ->where('source_id', $subscription->source_id)
            ->when($deliveredArticleIds !== [], function ($q) use ($deliveredArticleIds) {
                $q->whereNotIn('id', $deliveredArticleIds);
            })
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        if ($articles->isEmpty()) {
            return;
        }

        $sourceName = $subscription->source?->domain
            ?? $subscription->source?->title
            ?? 'RSS Feed';

        $articleData = $articles->map(fn (Article $article) => [
            'title' => $article->title,
            'url' => $article->canonical_url,
            'summary' => $article->summary,
            'image_url' => $article->image_url,
            'published_at' => $article->published_at?->format('M j, Y H:i'),
        ])->all();

        Mail::to($emailTarget)->send(new DailyDigestMail(
            sourceName: $sourceName,
            articles: $articleData,
        ));

        // Mark all as delivered
        foreach ($articles as $article) {
            Delivery::query()->firstOrCreate(
                [
                    'article_id' => $article->id,
                    'subscription_id' => $subscriptionId,
                    'channel' => 'email',
                ],
                [
                    'status' => 'delivered',
                    'attempts' => 1,
                    'queued_at' => now(),
                    'delivered_at' => now(),
                    'meta' => ['pipeline_stage' => PipelineStage::Deliver->value],
                ]
            );
        }

        $subscription->update(['last_delivered_at' => now()]);

        Log::info('EmailDigest: sent digest', [
            'subscription_id' => $subscriptionId,
            'email' => $emailTarget,
            'article_count' => $articles->count(),
        ]);
    }
}
