<?php

namespace App\Models;

use Database\Factories\SearchConsoleMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $report_date
 * @property int $clicks
 * @property int $impressions
 * @property float|null $position
 */
#[Fillable([
    'project_id', 'report_date', 'search_type', 'dimension_type', 'dimension_value',
    'dimension_hash', 'normalized_path', 'clicks', 'impressions', 'position',
])]
class SearchConsoleMetric extends Model
{
    /** @use HasFactory<SearchConsoleMetricFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'position' => 'float',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
