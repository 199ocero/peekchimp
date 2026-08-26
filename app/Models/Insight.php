<?php

namespace App\Models;

use Database\Factories\InsightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'fingerprint', 'category', 'type', 'severity', 'metric', 'current_value', 'previous_value', 'percentage_change', 'confidence', 'summary', 'explanation', 'recommendation', 'metadata', 'period_start', 'period_end', 'generated_at', 'expires_at', 'dismissed_at'])]
class Insight extends Model
{
    /** @use HasFactory<InsightFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'current_value' => 'float',
            'previous_value' => 'float',
            'percentage_change' => 'float',
            'metadata' => 'array',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<InsightActionAttempt, $this> */
    public function actionAttempts(): HasMany
    {
        return $this->hasMany(InsightActionAttempt::class);
    }
}
