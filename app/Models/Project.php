<?php

namespace App\Models;

use App\Enums\PublicDashboardSection;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'site_key', 'timezone', 'is_active', 'settings'])]
#[Hidden(['public_share_token', 'public_share_token_hash'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'public_share_enabled_at' => 'datetime',
            'public_share_token' => 'encrypted',
            'settings' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultPublicDashboardSections(): array
    {
        return array_map(
            static fn (PublicDashboardSection $section): string => $section->value,
            PublicDashboardSection::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function publicDashboardSections(): array
    {
        $sections = data_get($this->settings ?? [], 'public_dashboard.sections');

        if (! is_array($sections)) {
            return self::defaultPublicDashboardSections();
        }

        $allowedSections = self::defaultPublicDashboardSections();
        $sections = array_values(array_unique(array_filter(
            $sections,
            static fn (mixed $section): bool => is_string($section) && in_array($section, $allowedSections, true),
        )));

        return $sections === [] ? self::defaultPublicDashboardSections() : $sections;
    }

    public function hasPublicSharingEnabled(): bool
    {
        return $this->public_share_enabled_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ProjectDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(ProjectDomain::class);
    }

    /** @return HasMany<AnalyticsEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    /** @return HasMany<AnalyticsSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(AnalyticsSession::class);
    }

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }
}
