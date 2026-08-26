<?php

namespace App\Models;

use Database\Factories\AnalyticsRollupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'granularity', 'bucket_start', 'dimension', 'dimension_value', 'pageviews', 'visitors', 'visits', 'events', 'bounces', 'duration_seconds', 'conversions'])]
class AnalyticsRollup extends Model
{
    /** @use HasFactory<AnalyticsRollupFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'bucket_start' => 'immutable_datetime',
            'pageviews' => 'integer',
            'visitors' => 'integer',
            'visits' => 'integer',
            'events' => 'integer',
            'bounces' => 'integer',
            'duration_seconds' => 'integer',
            'conversions' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
