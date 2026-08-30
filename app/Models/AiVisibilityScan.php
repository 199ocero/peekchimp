<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiVisibilityScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $findings
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 */
#[Fillable(['project_id', 'status', 'score', 'findings', 'error', 'started_at', 'completed_at'])]
class AiVisibilityScan extends Model
{
    /** @use HasFactory<AiVisibilityScanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'findings' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<WebsitePageSnapshot, $this> */
    public function pageSnapshots(): HasMany
    {
        return $this->hasMany(WebsitePageSnapshot::class);
    }
}
