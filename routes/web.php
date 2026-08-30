<?php

use App\Http\Controllers\AiTrafficController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatConversationController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\ImportantActionController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PublicDashboardController;
use App\Http\Controllers\SearchConsoleConnectionController;
use App\Http\Controllers\SearchConsoleOAuthController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WebsiteCrawlController;
use App\Http\Controllers\WebsiteSettingsController;
use App\Http\Controllers\WebsiteSharingController;
use App\Http\Middleware\PublicShareHeaders;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'signed', 'throttle:6,1'])->group(function () {
    Route::get('invitations/{invitation}', [InvitationAcceptanceController::class, 'show'])
        ->name('invitations.show');
    Route::post('invitations/{invitation}', [InvitationAcceptanceController::class, 'store'])
        ->name('invitations.store');
});

Route::inertia('/', 'Welcome')->name('home');

Route::get('share/{token}', PublicDashboardController::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware([PublicShareHeaders::class, 'throttle:60,1'])
    ->name('shared.dashboard.show');

Route::middleware('auth')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('onboarding/website', [OnboardingController::class, 'store'])->name('onboarding.website.store');

    Route::get('websites/create', [WebsiteController::class, 'create'])->name('websites.create');
    Route::post('websites', [WebsiteController::class, 'store'])->name('websites.store');
    Route::get('websites/{project}/setup', [WebsiteController::class, 'setup'])->name('websites.setup');
    Route::patch('websites/{project}', [WebsiteController::class, 'update'])->name('websites.update');
    Route::patch('websites/{project}/current', [WebsiteController::class, 'select'])->name('websites.current');
});

Route::middleware(['auth', 'verified', 'website.configured'])->group(function () {
    Route::get('websites/{project}/search-console/connect', [SearchConsoleOAuthController::class, 'connect'])
        ->middleware('throttle:10,1')
        ->name('websites.search-console.connect');
    Route::get('integrations/google-search-console/callback', [SearchConsoleOAuthController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('google-search-console.callback');
    Route::post('websites/{project}/search-console', [SearchConsoleConnectionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('websites.search-console.store');
    Route::post('websites/{project}/search-console/sync', [SearchConsoleConnectionController::class, 'sync'])
        ->middleware('throttle:10,1')
        ->name('websites.search-console.sync');
    Route::delete('websites/{project}/search-console', [SearchConsoleConnectionController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('websites.search-console.destroy');
    Route::get('websites/{project}/settings', [WebsiteSettingsController::class, 'edit'])
        ->name('websites.settings.edit');
    Route::patch('websites/{project}/settings', [WebsiteSettingsController::class, 'update'])
        ->name('websites.settings.update');
    Route::post('websites/{project}/crawl', WebsiteCrawlController::class)
        ->name('websites.crawl.store');
    Route::patch('websites/{project}/sharing', [WebsiteSharingController::class, 'update'])
        ->name('websites.sharing.update');
    Route::post('websites/{project}/sharing/rotate', [WebsiteSharingController::class, 'rotate'])
        ->name('websites.sharing.rotate');
    Route::get('websites/{project}/actions', [ImportantActionController::class, 'index'])
        ->name('websites.actions.index');
    Route::post('websites/{project}/actions', [ImportantActionController::class, 'store'])
        ->name('websites.actions.store');
    Route::patch('websites/{project}/actions/{action}', [ImportantActionController::class, 'update'])
        ->name('websites.actions.update');
    Route::delete('websites/{project}/actions/{action}', [ImportantActionController::class, 'destroy'])
        ->name('websites.actions.destroy');
    Route::get('websites/{project}/goals', [GoalController::class, 'index'])
        ->name('websites.goals.index');
    Route::post('websites/{project}/goals', [GoalController::class, 'store'])
        ->name('websites.goals.store');
    Route::patch('websites/{project}/goals/{goal}', [GoalController::class, 'update'])
        ->name('websites.goals.update');
    Route::delete('websites/{project}/goals/{goal}', [GoalController::class, 'destroy'])
        ->name('websites.goals.destroy');
    Route::get('websites/{project}/funnels', [FunnelController::class, 'index'])
        ->name('websites.funnels.index');
    Route::post('websites/{project}/funnels', [FunnelController::class, 'store'])
        ->name('websites.funnels.store');
    Route::patch('websites/{project}/funnels/{funnel}', [FunnelController::class, 'update'])
        ->name('websites.funnels.update');
    Route::delete('websites/{project}/funnels/{funnel}', [FunnelController::class, 'destroy'])
        ->name('websites.funnels.destroy');
});

Route::middleware(['auth', 'verified', 'website.configured'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])
        ->whereUuid('conversation')
        ->name('chat.show');
    Route::post('chat/messages', [ChatMessageController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('chat.messages.store');
    Route::patch('chat/{conversation}', [ChatConversationController::class, 'update'])
        ->whereUuid('conversation')
        ->name('chat.update');
    Route::delete('chat/{conversation}', [ChatConversationController::class, 'destroy'])
        ->whereUuid('conversation')
        ->name('chat.destroy');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('websites/{project}/ai-traffic', AiTrafficController::class)->name('websites.ai-traffic');
});

require __DIR__.'/settings.php';
