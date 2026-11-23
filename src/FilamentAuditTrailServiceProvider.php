<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Madbox99\FilamentAuditTrail\Commands\ReconstructCommand;
use Madbox99\FilamentAuditTrail\Listeners\AuthEventSubscriber;
use Madbox99\FilamentAuditTrail\Listeners\ModelChangeListener;
use Madbox99\FilamentAuditTrail\Livewire\ActionCallHook;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;

final class FilamentAuditTrailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-trail.php', 'audit-trail');

        $this->app->singleton(AuditRecorder::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'audit-trail');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::subscribe(AuthEventSubscriber::class);

        foreach (['created', 'updated', 'deleted', 'restored'] as $name) {
            Event::listen(
                'eloquent.'.$name.': *',
                fn (string $event, array $payload) => $this->app->make(ModelChangeListener::class)->handle($event, $payload),
            );
        }

        $this->app->make(ActionCallHook::class)->register();

        if ($this->app->runningInConsole()) {
            $this->commands([ReconstructCommand::class]);

            $this->publishes([
                __DIR__.'/../config/audit-trail.php' => config_path('audit-trail.php'),
            ], 'audit-trail-config');
        }
    }
}
