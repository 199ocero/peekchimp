<?php

namespace App\Http\Controllers;

use App\Actions\Members\AcceptMemberInvitationAction;
use App\Http\Requests\AcceptInvitationRequest;
use App\Models\MemberInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, MemberInvitation $invitation): Response
    {
        abort_if($invitation->isExpired(), 410, 'This invitation has expired.');

        return Inertia::render('auth/AcceptInvitation', [
            'email' => $invitation->email,
            'acceptUrl' => $request->fullUrl(),
        ]);
    }

    public function store(
        AcceptInvitationRequest $request,
        MemberInvitation $invitation,
        AcceptMemberInvitationAction $acceptInvitation,
    ): RedirectResponse {
        $user = $acceptInvitation->handle($invitation, $request->credentials());

        Auth::login($user);
        $request->session()->regenerate();

        return $user->hasCompletedWebsiteSetup()
            ? to_route('dashboard')
            : to_route('onboarding.show');
    }
}
