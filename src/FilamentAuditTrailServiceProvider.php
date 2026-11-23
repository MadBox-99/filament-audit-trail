<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail;

use Illuminate\Support\ServiceProvider;

final class FilamentAuditTrailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-trail.php', 'audit-trail');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'audit-trail');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/audit-trail.php' => config_path('audit-trail.php'),
            ], 'audit-trail-config');
        }
    }
}
