<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->user()->is($user->workspaceOwnerUser()) && $project->is_active;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && ! $project->domains()->where('is_verified', true)->exists();
    }

    public function manage(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function share(User $user, Project $project): bool
    {
        return $this->manage($user, $project)
            && $project->domains()->where('is_verified', true)->exists();
    }

    public function select(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && $project->domains()->where('is_verified', true)->exists();
    }
}
