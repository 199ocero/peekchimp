<?php

namespace App\Actions\Websites;

use App\Models\Project;
use App\Models\User;

class SelectCurrentWebsiteAction
{
    public function handle(User $user, Project $project): void
    {
        $user->currentProject()->associate($project);
        $user->save();
    }
}
