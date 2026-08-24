<?php

namespace App\Models;

use Database\Factories\ProjectDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'domain', 'is_verified'])]
class ProjectDomain extends Model
{
    /** @use HasFactory<ProjectDomainFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_verified' => 'boolean'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
