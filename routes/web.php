<?php

use App\Http\Controllers\AdminDebugController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeedGeneratorController;
use App\Http\Controllers\FeedProfileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TelegramIntegrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('login.store');
    Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])->name('auth.google.callback');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/integrations/status', [IntegrationController::class, 'status'])->name('integrations.status');
    Route::get('/feeds/new', [FeedGeneratorController::class, 'index'])->name('feeds.generator');
    Route::post('/feeds/generate', [FeedGeneratorController::class, 'generate'])
        ->middleware('throttle:feed-generate')
        ->name('feeds.generate');
    Route::get('/feeds/generate/{feedGeneration}', [FeedGeneratorController::class, 'status'])
        ->middleware('throttle:feed-status')
        ->name('feeds.generate.status');
    Route::get('/feeds/generate/{feedGeneration}/stream', [FeedGeneratorController::class, 'stream'])
        ->middleware('throttle:feed-stream')
        ->name('feeds.generate.stream');
    Route::get('/feeds/{source}/stream', [FeedProfileController::class, 'stream'])
        ->middleware('throttle:feed-profile-stream')
        ->name('feeds.show.stream');
    Route::get('/feeds/{source}', [FeedProfileController::class, 'show'])->name('feeds.show');

    Route::post('/subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::patch('/subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
    Route::post('/telegram/connect', [TelegramIntegrationController::class, 'connect'])->name('telegram.connect');
    Route::post('/telegram/verify', [TelegramIntegrationController::class, 'verify'])->name('telegram.verify');
    Route::delete('/telegram/chats/{telegramChat}', [TelegramIntegrationController::class, 'destroy'])
        ->name('telegram.chats.destroy');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/debug/generations', [AdminDebugController::class, 'index'])
            ->name('debug.generations');
        Route::post('/debug/generations/{feedGeneration}/retry', [AdminDebugController::class, 'retry'])
            ->name('debug.generations.retry');
        Route::post('/debug/sources/{source}/retry', [AdminDebugController::class, 'retrySource'])
            ->name('debug.sources.retry');
        Route::get('/debug/sources/{source}/snapshot', [AdminDebugController::class, 'sourceSnapshot'])
            ->name('debug.sources.snapshot');
    });
