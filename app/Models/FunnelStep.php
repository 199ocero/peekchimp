<?php

namespace App\Models;

use Database\Factories\FunnelStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['funnel_id', 'position', 'name', 'type', 'event_name', 'path', 'path_operator'])]
class FunnelStep extends Model
{
    /** @use HasFactory<FunnelStepFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }
}
