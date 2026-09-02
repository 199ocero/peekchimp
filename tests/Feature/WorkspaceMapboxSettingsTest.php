<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('workspace admins can save and remove an encrypted Mapbox public token', function () {
    $admin = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $token = 'pk.'.str_repeat('a', 80);

    $this->actingAs($admin)
        ->get(route('settings.mapbox.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Mapbox')
            ->where('hasToken', false),
        );

    $this->actingAs($admin)
        ->patch(route('settings.mapbox.update'), ['mapbox_public_token' => $token])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.mapbox.edit'));

    expect($admin->refresh()->mapbox_public_token)->toBe($token)
        ->and(DB::table('users')->where('id', $admin->getKey())->value('mapbox_public_token'))->not->toBe($token);

    $this->actingAs($admin)
        ->delete(route('settings.mapbox.destroy'))
        ->assertRedirect(route('settings.mapbox.edit'));

    expect($admin->refresh()->mapbox_public_token)->toBeNull();
});

test('Mapbox settings reject secret tokens', function () {
    $admin = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->patch(route('settings.mapbox.update'), [
            'mapbox_public_token' => 'sk.'.str_repeat('a', 80),
        ])
        ->assertSessionHasErrors('mapbox_public_token');

    expect($admin->refresh()->mapbox_public_token)->toBeNull();
});

test('workspace members cannot manage Mapbox settings', function () {
    $admin = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $member = User::factory()->create(['workspace_owner_id' => $admin->getKey()]);

    $this->actingAs($member)
        ->get(route('settings.mapbox.edit'))
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('settings.mapbox.update'), [
            'mapbox_public_token' => 'pk.'.str_repeat('a', 80),
        ])
        ->assertForbidden();
});

test('workspace members receive the owner Mapbox token on the dashboard', function () {
    $token = 'pk.'.str_repeat('b', 80);
    $admin = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $admin->forceFill(['mapbox_public_token' => $token])->save();
    $member = User::factory()->create(['workspace_owner_id' => $admin->getKey()]);

    $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('mapbox.accessToken', $token)
        ->where('mapbox.canManage', false));
});
