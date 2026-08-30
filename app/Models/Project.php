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
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property array<string, mixed>|null $settings */
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

    /**
     * @return array{audience: string, products_services: string, value_proposition: string, brand_voice: string, primary_conversion_goals: array<int, string>}
     */
    public function growthContext(): array
    {
        $context = data_get($this->settings ?? [], 'growth_context', []);
        $conversionGoals = data_get($context, 'primary_conversion_goals', []);
        if (! is_array($conversionGoals)) {
            $conversionGoals = [];
        }

        $audience = data_get($context, 'audience');
        $productsServices = data_get($context, 'products_services');
        $valueProposition = data_get($context, 'value_proposition');
        $brandVoice = data_get($context, 'brand_voice');

        return [
            'audience' => is_string($audience) ? $audience : '',
            'products_services' => is_string($productsServices) ? $productsServices : '',
            'value_proposition' => is_string($valueProposition) ? $valueProposition : '',
            'brand_voice' => is_string($brandVoice) ? $brandVoice : '',
            'primary_conversion_goals' => collect($conversionGoals)
                ->filter(fn (mixed $goal): bool => is_string($goal) && $goal !== '')
                ->values()
                ->all(),
        ];
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

    /** @return HasMany<AnalyticsRollup, $this> */
    public function analyticsRollups(): HasMany
    {
        return $this->hasMany(AnalyticsRollup::class);
    }

    /** @return HasMany<Insight, $this> */
    public function insights(): HasMany
    {
        return $this->hasMany(Insight::class);
    }

    /** @return HasMany<ImportantAction, $this> */
    public function importantActions(): HasMany
    {
        return $this->hasMany(ImportantAction::class);
    }

    /** @return HasMany<Funnel, $this> */
    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    /** @return HasMany<AiVisibilityScan, $this> */
    public function aiVisibilityScans(): HasMany
    {
        return $this->hasMany(AiVisibilityScan::class);
    }

    /** @return HasMany<WebsitePageSnapshot, $this> */
    public function pageSnapshots(): HasMany
    {
        return $this->hasMany(WebsitePageSnapshot::class);
    }

    /** @return HasOne<SearchConsoleConnection, $this> */
    public function searchConsoleConnection(): HasOne
    {
        return $this->hasOne(SearchConsoleConnection::class);
    }

    /** @return HasMany<SearchConsoleMetric, $this> */
    public function searchConsoleMetrics(): HasMany
    {
        return $this->hasMany(SearchConsoleMetric::class);
    }
}
