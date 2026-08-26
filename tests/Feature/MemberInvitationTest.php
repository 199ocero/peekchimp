<?php

use App\Models\MemberInvitation;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

function adminWithWorkspace(): User
{
    return User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
}

function invitationUrl(MemberInvitation $invitation): string
{
    return URL::temporarySignedRoute(
        'invitations.show',
        $invitation->expires_at,
        ['invitation' => $invitation],
    );
}

test('admins can create and revoke member invitations', function () {
    $admin = adminWithWorkspace();

    $this->actingAs($admin)
        ->get(route('members.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Members')
            ->where('members', [])
            ->where('invitations', []),
        );

    $this->actingAs($admin)
        ->post(route('members.store'), ['email' => 'MEMBER@EXAMPLE.COM'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('members.edit'));

    $invitation = MemberInvitation::query()->sole();

    expect($invitation->email)->toBe('member@example.com')
        ->and($invitation->invitedBy->is($admin))->toBeTrue();

    $this->actingAs($admin)
        ->delete(route('members.destroy', $invitation))
        ->assertRedirect(route('members.edit'));

    expect($invitation->fresh())->toBeNull();
});

test('member status messages use theme-aware success text', function () {
    expect(file_get_contents(resource_path('js/pages/settings/Members.vue')))
        ->toContain('text-success')
        ->not->toContain('text-success-foreground');
});

test('non-admins cannot manage invitations', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($user)
        ->get(route('members.edit'))
        ->assertForbidden();
});

test('admins can create a password reset link and remove a workspace member', function () {
    $admin = adminWithWorkspace();
    $member = User::factory()->create([
        'workspace_owner_id' => $admin->getKey(),
        'email' => 'member@example.com',
    ]);

    $this->actingAs($admin)
        ->get(route('members.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members.0.id', $member->getKey())
            ->where('members.0.email', $member->email),
        );

    $this->actingAs($admin)
        ->post(route('members.password-reset.store', $member))
        ->assertRedirect(route('members.edit'))
        ->assertSessionHas('passwordResetLink', fn (string $resetLink): bool => str_contains($resetLink, 'reset-password/'));

    $this->actingAs($admin)
        ->delete(route('members.member.destroy', $member))
        ->assertRedirect(route('members.edit'));

    expect($member->fresh())->toBeNull();
});

test('admins cannot manage members outside their workspace', function () {
    $admin = adminWithWorkspace();
    $otherAdmin = adminWithWorkspace();
    $member = User::factory()->create([
        'workspace_owner_id' => $otherAdmin->getKey(),
    ]);

    $this->actingAs($admin)
        ->post(route('members.password-reset.store', $member))
        ->assertNotFound();

    $this->actingAs($admin)
        ->delete(route('members.member.destroy', $member))
        ->assertNotFound();

    expect($member->fresh())->not->toBeNull();
});

test('an invited member can join the shared workspace', function () {
    $admin = adminWithWorkspace();
    $invitation = MemberInvitation::factory()->create([
        'invited_by_id' => $admin->getKey(),
        'email' => 'member@example.com',
    ]);
    $url = invitationUrl($invitation);

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/AcceptInvitation')
            ->where('email', 'member@example.com')
            ->where('acceptUrl', $url),
        );

    $this->post($url, [
        'name' => 'Workspace Member',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard'));

    $member = User::query()->where('email', 'member@example.com')->sole();

    $this->assertAuthenticatedAs($member);
    expect($member->is_admin)->toBeFalse()
        ->and($member->workspace_owner_id)->toBe($admin->getKey())
        ->and($invitation->fresh())->toBeNull();

    $this->get(route('dashboard'))->assertOk();
});

test('workspace members can manage the admin workspace websites', function () {
    $admin = adminWithWorkspace();
    $member = User::factory()->create([
        'workspace_owner_id' => $admin->getKey(),
    ]);

    $this->actingAs($member)
        ->post(route('websites.store'), [
            'name' => 'Member website',
            'url' => 'https://member.example.test',
            'timezone' => 'UTC',
        ])
        ->assertRedirect();

    $project = $admin->projects()->where('name', 'Member website')->sole();

    expect($project->user()->is($admin))->toBeTrue();

    $this->actingAs($member)
        ->patch(route('websites.settings.update', $project), [
            'name' => 'Updated by member',
            'timezone' => 'Asia/Manila',
        ])
        ->assertRedirect(route('websites.settings.edit', $project));
});

test('expired invitations cannot be accepted', function () {
    $admin = adminWithWorkspace();
    $invitation = MemberInvitation::factory()->expired()->create([
        'invited_by_id' => $admin->getKey(),
    ]);

    $this->get(invitationUrl($invitation))->assertForbidden();
});

test('the admin cannot delete their account', function () {
    $admin = adminWithWorkspace();

    $this->actingAs($admin)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertForbidden();

    expect($admin->fresh())->not->toBeNull();
});
