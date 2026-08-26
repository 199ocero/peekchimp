<?php

use App\Http\Controllers\MemberInvitationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\WorkspaceAiSettingsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'website.configured'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'website.configured'])->group(function () {
    Route::get('settings/members', [MemberInvitationController::class, 'edit'])
        ->name('members.edit');
    Route::post('settings/member-invitations', [MemberInvitationController::class, 'store'])
        ->name('members.store');
    Route::delete('settings/member-invitations/{invitation}', [MemberInvitationController::class, 'destroy'])
        ->name('members.destroy');
    Route::post('settings/members/{member}/password-reset', [MemberInvitationController::class, 'createPasswordResetLink'])
        ->name('members.password-reset.store');
    Route::delete('settings/members/{member}', [MemberInvitationController::class, 'destroyMember'])
        ->name('members.member.destroy');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
    Route::get('settings/ai', [WorkspaceAiSettingsController::class, 'edit'])
        ->name('settings.ai.edit');
    Route::patch('settings/ai', [WorkspaceAiSettingsController::class, 'update'])
        ->name('settings.ai.update');
    Route::post('settings/ai/test', [WorkspaceAiSettingsController::class, 'test'])
        ->name('settings.ai.test');
});
