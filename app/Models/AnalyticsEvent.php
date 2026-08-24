<?php

namespace App\Models;

use Database\Factories\AnalyticsEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'event_id', 'event_name', 'platform', 'visitor_id', 'session_id', 'path', 'referrer_host', 'country', 'device', 'browser', 'operating_system', 'utm_source', 'utm_medium', 'utm_campaign', 'properties', 'occurred_at'])]
class AnalyticsEvent extends Model
{
    protected $table = 'events';

    /** @use HasFactory<AnalyticsEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
