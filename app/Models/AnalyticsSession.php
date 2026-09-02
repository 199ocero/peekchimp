<?php

namespace App\Models;

use Database\Factories\AnalyticsSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'session_id', 'visitor_id', 'started_at', 'last_seen_at', 'pageviews', 'custom_events', 'duration_seconds', 'is_bounce', 'entry_path', 'exit_path', 'referrer_host', 'country', 'latitude', 'longitude', 'device', 'browser', 'operating_system', 'utm_source', 'utm_medium', 'utm_campaign'])]
class AnalyticsSession extends Model
{
    /** @use HasFactory<AnalyticsSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'is_bounce' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
