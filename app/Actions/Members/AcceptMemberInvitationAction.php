<?php

namespace App\Actions\Members;

use App\Models\MemberInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptMemberInvitationAction
{
    /**
     * @param  array{name: string, password: string}  $data
     */
    public function handle(MemberInvitation $invitation, array $data): User
    {
        return DB::transaction(function () use ($invitation, $data): User {
            $invitation = MemberInvitation::query()
                ->whereKey($invitation)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invitation->isExpired()) {
                throw ValidationException::withMessages([
                    'email' => 'This invitation has expired. Ask the admin for a new link.',
                ]);
            }

            $admin = $invitation->invitedBy()->lockForUpdate()->firstOrFail();

            if (! $admin->is_admin) {
                throw ValidationException::withMessages([
                    'email' => 'This invitation is no longer available.',
                ]);
            }

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'A user with this email address already exists.',
                ]);
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'is_admin' => false,
                'workspace_owner_id' => $admin->getKey(),
            ]);

            $invitation->delete();

            return $user;
        });
    }
}
