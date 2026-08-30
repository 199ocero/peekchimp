<?php

namespace App\Providers;

use App\Contracts\Analytics\InsightActionProvider;
use App\Contracts\SearchConsoleClient;
use App\Models\User;
use App\Services\Analytics\InternalInsightActionProvider;
use App\Services\SearchConsole\GoogleSearchConsoleClient;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InsightActionProvider::class, InternalInsightActionProvider::class);
        $this->app->bind(SearchConsoleClient::class, GoogleSearchConsoleClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('analytics-ingestion', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->ip());
        });

        RateLimiter::for('mcp', function (Request $request): Limit {
            return Limit::perMinute(120)->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        Passport::authorizationView('mcp.authorize');
        Passport::tokensExpireIn(new DateInterval('PT1H'));
        Passport::refreshTokensExpireIn(new DateInterval('P30D'));

        Gate::define('manageMembers', static fn (?User $user): bool => $user?->is_admin === true);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
