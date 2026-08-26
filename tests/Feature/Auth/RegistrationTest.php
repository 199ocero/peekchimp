<?php

use App\Models\Project;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('onboarding.show', absolute: false));
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'is_admin' => true]);
    expect(Project::query()->count())->toBe(0);
});

test('registration remains available when no admin exists', function () {
    User::factory()->create();

    $this->get(route('register'))->assertOk();
});

test('registration is disabled after an admin is registered', function () {
    User::factory()->create(['is_admin' => true]);

    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Registration is available by invitation only.');

    $this->post(route('register.store'), [
        'name' => 'Another User',
        'email' => 'another@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();

    expect(User::query()->count())->toBe(1);
});
