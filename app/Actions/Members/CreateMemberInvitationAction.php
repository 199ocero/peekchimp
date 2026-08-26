<?php

namespace App\Actions\Members;

use App\Models\MemberInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateMemberInvitationAction
{
    public function handle(User $admin, string $email): MemberInvitation
    {
        return DB::transaction(function () use ($admin, $email): MemberInvitation {
            $email = Str::lower($email);

            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'A user with this email address already exists.',
                ]);
            }

            $existingInvitation = MemberInvitation::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($existingInvitation !== null) {
                if (! $existingInvitation->isExpired()) {
                    throw ValidationException::withMessages([
                        'email' => 'An invitation for this email address is already active.',
                    ]);
                }

                $existingInvitation->delete();
            }

            return $admin->memberInvitations()->create([
                'email' => $email,
                'expires_at' => now()->addDays(7),
            ]);
        });
    }
}
