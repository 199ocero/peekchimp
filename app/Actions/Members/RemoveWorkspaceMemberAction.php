<?php

namespace App\Actions\Members;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveWorkspaceMemberAction
{
    public function handle(User $admin, User $member): void
    {
        DB::transaction(function () use ($admin, $member): void {
            abort_unless(
                ! $member->is_admin && $member->workspace_owner_id === $admin->getKey(),
                404,
            );

            $member->delete();
        });
    }
}
