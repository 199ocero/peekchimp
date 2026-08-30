<?php

namespace App\Models;

use Database\Factories\SearchConsoleConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $access_token
 * @property string $refresh_token
 * @property Carbon|null $access_token_expires_at
 * @property Carbon|null $data_through
 * @property Carbon|null $sync_started_at
 * @property Carbon|null $last_synced_at
 */
#[Fillable([
    'project_id', 'connected_by_user_id', 'property_site_url', 'property_type', 'permission_level',
    'access_token', 'refresh_token', 'access_token_expires_at', 'status', 'sync_batch_id',
    'data_through', 'sync_started_at', 'last_synced_at', 'last_error',
])]
#[Hidden(['access_token', 'refresh_token'])]
class SearchConsoleConnection extends Model
{
    /** @use HasFactory<SearchConsoleConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'immutable_datetime',
            'data_through' => 'immutable_date',
            'sync_started_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }
}
