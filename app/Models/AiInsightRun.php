<?php

namespace App\Models;

use Database\Factories\AiInsightRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'context_hash', 'provider', 'model', 'status', 'candidate_count', 'input_tokens', 'output_tokens', 'error', 'started_at', 'completed_at'])]
class AiInsightRun extends Model
{
    /** @use HasFactory<AiInsightRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'candidate_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
