<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function (): array {
        return [
            'status' => 'ok',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'timestamp' => now()->toIso8601String(),
        ];
    });

    Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
        ->name('api.telegram.webhook');
});
