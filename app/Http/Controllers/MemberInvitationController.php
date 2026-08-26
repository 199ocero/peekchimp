<?php

namespace App\Http\Controllers;

use App\Actions\Members\CreateMemberInvitationAction;
use App\Actions\Members\RemoveWorkspaceMemberAction;
use App\Http\Requests\MemberInvitationRequest;
use App\Models\MemberInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class MemberInvitationController extends Controller
{
    public function edit(Request $request): Response
    {
        Gate::authorize('manageMembers');

        $admin = $request->user();
        abort_unless($admin instanceof User, 403);
        $invitations = $admin->memberInvitations()->latest()->get();
        $members = $admin->members()
            ->where('is_admin', false)
            ->oldest()
            ->get();

        return Inertia::render('settings/Members', [
            'members' => $members
                ->map(fn (User $member): array => $this->memberData($member))
                ->values()
                ->all(),
            'invitations' => $invitations
                ->map(fn (MemberInvitation $invitation): array => $this->invitationData($invitation))
                ->values()
                ->all(),
            'status' => $request->session()->get('status'),
            'passwordResetLink' => $request->session()->get('passwordResetLink'),
        ]);
    }

    public function store(
        MemberInvitationRequest $request,
        CreateMemberInvitationAction $createInvitation,
    ): RedirectResponse {
        $admin = $request->user();
        abort_unless($admin instanceof User, 403);

        $createInvitation->handle($admin, $request->string('email')->toString());

        return to_route('members.edit')
            ->with('status', 'Invitation created.');
    }

    public function destroy(Request $request, MemberInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers');

        abort_unless($invitation->invitedBy()->is($request->user()), 404);

        $invitation->delete();

        return to_route('members.edit')
            ->with('status', 'Invitation revoked.');
    }

    public function createPasswordResetLink(Request $request, User $member): RedirectResponse
    {
        Gate::authorize('manageMembers');

        $admin = $request->user();
        abort_unless($admin instanceof User, 403);
        $this->assertWorkspaceMember($admin, $member);

        $token = Password::broker()->createToken($member);
        $url = URL::route('password.reset', [
            'token' => $token,
            'email' => $member->email,
        ]);

        return to_route('members.edit')
            ->with('status', 'Password reset link created. Copy it and send it to the member.')
            ->with('passwordResetLink', $url);
    }

    public function destroyMember(
        Request $request,
        User $member,
        RemoveWorkspaceMemberAction $removeMember,
    ): RedirectResponse {
        Gate::authorize('manageMembers');

        $admin = $request->user();
        abort_unless($admin instanceof User, 403);
        $removeMember->handle($admin, $member);

        return to_route('members.edit')
            ->with('status', 'Member removed.');
    }

    /**
     * @return array{id: int, name: string, email: string, createdAt: string}
     */
    private function memberData(User $member): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'email' => $member->email,
            'createdAt' => $member->created_at?->toIso8601String() ?? '',
        ];
    }

    private function assertWorkspaceMember(User $admin, User $member): void
    {
        abort_unless(
            ! $member->is_admin && $member->workspace_owner_id === $admin->getKey(),
            404,
        );
    }

    /**
     * @return array{id: int, email: string, expiresAt: string, url: string|null}
     */
    private function invitationData(MemberInvitation $invitation): array
    {
        return [
            'id' => $invitation->getKey(),
            'email' => $invitation->email,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'url' => $invitation->isExpired()
                ? null
                : URL::temporarySignedRoute(
                    'invitations.show',
                    $invitation->expires_at,
                    ['invitation' => $invitation],
                ),
        ];
    }
}
