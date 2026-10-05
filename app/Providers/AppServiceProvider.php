<?php

namespace App\Providers;

use App\Application\Traders\BuildTraderPerformanceReport;
use App\Etoro\EtoroWriteGuard;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Request-scoped so the trader view page and its widgets share one
        // memoized report per request (and queue workers start fresh).
        $this->app->scoped(BuildTraderPerformanceReport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        app(EtoroWriteGuard::class)->ensureReadOnly();
    }

    /**
     * One shared budget for every queued eToro job (PROJECT.md §16): the
     * eToro default quota is shared across endpoints, so a per-job limit
     * would not protect it.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('etoro-api', fn (): Limit => Limit::perMinute(max(1, (int) config('etoro.requests_per_minute'))));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

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
