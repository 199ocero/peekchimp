<?php

namespace App\Models;

use Database\Factories\InsightActionAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['insight_id', 'user_id', 'action_key', 'status', 'outcome', 'metadata', 'acted_at'])]
class InsightActionAttempt extends Model
{
    /** @use HasFactory<InsightActionAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'acted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Insight, $this> */
    public function insight(): BelongsTo
    {
        return $this->belongsTo(Insight::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
