<?php

namespace App\Models;

use Database\Factories\WorkspaceAiSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_owner_id', 'provider', 'model', 'api_key', 'base_url', 'is_enabled', 'status', 'last_tested_at', 'last_error'])]
#[Hidden(['api_key'])]
class WorkspaceAiSetting extends Model
{
    /** @use HasFactory<WorkspaceAiSettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_enabled' => 'boolean',
            'last_tested_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function workspaceOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'workspace_owner_id');
    }
}
