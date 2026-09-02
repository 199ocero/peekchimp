<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Ai\Concerns\HasConversations;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property int|null $workspace_owner_id
 * @property int|null $current_project_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $mapbox_public_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'workspace_owner_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'mapbox_public_token', 'is_admin', 'current_project_id', 'workspace_owner_id'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasConversations, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'mapbox_public_token' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<User, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(self::class, 'workspace_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function workspaceOwner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'workspace_owner_id');
    }

    /** @return HasMany<MemberInvitation, $this> */
    public function memberInvitations(): HasMany
    {
        return $this->hasMany(MemberInvitation::class, 'invited_by_id');
    }

    public function workspaceOwnerUser(): self
    {
        return $this->is_admin ? $this : ($this->workspaceOwner()->first() ?? $this);
    }

    /** @return BelongsTo<Project, $this> */
    public function currentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'current_project_id');
    }

    /** @return HasOne<WorkspaceAiSetting, $this> */
    public function workspaceAiSetting(): HasOne
    {
        return $this->hasOne(WorkspaceAiSetting::class, 'workspace_owner_id');
    }

    public function hasCompletedWebsiteSetup(): bool
    {
        return $this->workspaceOwnerUser()->projects()
            ->where('is_active', true)
            ->whereHas('domains', fn ($query) => $query->where('is_verified', true))
            ->exists();
    }
}
