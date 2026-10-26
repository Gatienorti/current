<?php

namespace HorizonLens;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Horizon\Events\JobPushed;

final class LensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $legacy = config('horizon-lens', []);
        $current = config('current', []);
        $this->mergeConfigFrom(__DIR__.'/../config/current.php', 'current');
        $settings = array_replace(config('current'), $legacy, $current);
        config()->set('current', $settings);
        config()->set('horizon-lens', $settings);
        if (! config('database.connections.current')) {
            config()->set('database.connections.current', [
                'driver' => 'sqlite', 'database' => storage_path('current/current.sqlite'),
                'prefix' => '', 'foreign_key_constraints' => true,
                'busy_timeout' => 250, 'journal_mode' => 'WAL',
            ]);
        }
        $this->app->singleton(Store::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(SourceOutline::class);
    }

    public function boot(): void
    {
        Broadcasts\ReverbIntegration::configure();
        $this->callAfterResolving(BroadcastManager::class, fn ($manager) => Broadcasts\ReverbIntegration::register($manager));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'horizon-lens');
        $this->app['view']->composer('horizon-lens::layout', function ($view) {
            $view->with('currentVersion', \Composer\InstalledVersions::isInstalled('horizon-lens/horizon-lens')
                ? (\Composer\InstalledVersions::getPrettyVersion('horizon-lens/horizon-lens') ?? 'Development')
                : 'Development');
            $view->with('broadcastsVisible', Broadcasts\ReverbIntegration::visible());
            $view->with('currentMark', file_get_contents(__DIR__.'/../resources/brand/current-mark.svg'));
            $view->with('lensThemeVersion', filemtime(__DIR__.'/../resources/js/theme.js'));
            $view->with('lensFiltersVersion', filemtime(__DIR__.'/../resources/js/filters.js'));
            $view->with('lensMapVersion', filemtime(__DIR__.'/../resources/js/map.js'));
            $view->with('lensStepsVersion', filemtime(__DIR__.'/../resources/js/steps.js'));
            $view->with('lensRefreshVersion', filemtime(__DIR__.'/../resources/js/refresh.js'));
            $view->with('lensCssVersion', filemtime(__DIR__.'/../resources/css/lens.css'));
        });
        $this->publishes([__DIR__.'/../config/current.php' => config_path('current.php')], 'current-config');
        $this->publishes([__DIR__.'/../config/current.php' => config_path('horizon-lens.php')], 'horizon-lens-config');
        Route::prefix(config('horizon-lens.path'))->name('horizon-lens.')->middleware(config('horizon-lens.middleware'))->group(function () {
            Route::get('/', [Http\JobsController::class, 'index'])->name('index');
            foreach (['dashboard', 'recent', 'pending', 'processing', 'completed', 'failed', 'silenced'] as $section) {
                $path = in_array($section, ['dashboard', 'silenced'], true) ? '/'.$section : '/jobs/'.$section;
                Route::get($path, [Http\JobsController::class, 'index'])->defaults('section', $section)->name($section);
            }
            Route::get('/monitoring', [Http\JobsController::class, 'index'])->defaults('section', 'monitoring')->name('monitoring');
            foreach (['metrics', 'batches'] as $section) {
                Route::get('/'.$section, [Http\SectionsController::class, 'horizon'])->defaults('section', $section)->name($section);
            }
            foreach (['jobs', 'queues'] as $kind) {
                Route::get('/metrics/'.$kind, [Http\SectionsController::class, 'horizon'])->defaults('section', 'metrics')->defaults('metricKind', $kind)->name('metrics.'.$kind);
            }
            foreach (['webhooks', 'broadcasts'] as $area) {
                Route::get('/'.$area.'/dashboard', Http\ActivityDashboardController::class)->defaults('area', $area)->name($area.'.dashboard');
            }
            Route::get('/webhooks', [Http\WebhooksController::class, 'index'])->name('webhooks');
            Route::get('/webhooks/outgoing', [Http\OutgoingWebhooksController::class, 'index'])->name('webhooks.outgoing');
            Route::get('/webhooks/outgoing/{id}', [Http\OutgoingWebhooksController::class, 'show'])->name('webhooks.outgoing.show')->where('id', '[A-Z0-9]{26}');
            Route::get('/webhooks/{id}', [Http\WebhooksController::class, 'show'])->name('webhooks.show')->where('id', '[A-Z0-9]{26}');
            Route::get('/broadcasts', [Http\BroadcastsController::class, 'index'])->name('broadcasts');
            Route::get('/broadcasts/authorization', Http\BroadcastAuthorizationController::class)->name('broadcasts.authorization');
            Route::get('/broadcasts/{id}/compare', [Http\BroadcastsController::class, 'compare'])->name('broadcasts.compare')->where('id', '[A-Z0-9]{26}');
            Route::get('/broadcasts/{id}', [Http\BroadcastsController::class, 'show'])->name('broadcasts.show')->where('id', '[A-Z0-9]{26}');
            Route::get('/assets/lens.css', fn () => response(file_get_contents(__DIR__.'/../resources/css/lens.css'), 200, ['Content-Type' => 'text/css', 'Cache-Control' => 'public, max-age=3600']))->name('css');
            Route::get('/assets/theme.js', fn () => response(file_get_contents(__DIR__.'/../resources/js/theme.js'), 200, ['Content-Type' => 'text/javascript', 'Cache-Control' => 'public, max-age=3600']))->name('theme');
            Route::get('/assets/filters.js', fn () => response(file_get_contents(__DIR__.'/../resources/js/filters.js'), 200, ['Content-Type' => 'text/javascript', 'Cache-Control' => 'public, max-age=3600']))->name('filters');
            Route::get('/assets/steps.js', fn () => response(file_get_contents(__DIR__.'/../resources/js/steps.js'), 200, ['Content-Type' => 'text/javascript', 'Cache-Control' => 'public, max-age=3600']))->name('steps');
            Route::get('/assets/map.js', fn () => response(file_get_contents(__DIR__.'/../resources/js/map.js'), 200, ['Content-Type' => 'text/javascript', 'Cache-Control' => 'public, max-age=3600']))->name('map');
            Route::get('/assets/refresh.js', fn () => response(file_get_contents(__DIR__.'/../resources/js/refresh.js'), 200, ['Content-Type' => 'text/javascript', 'Cache-Control' => 'public, max-age=3600']))->name('refresh');
            Route::post('/jobs/{id}/retry', Http\RetryController::class)->name('retry')->where('id', '[A-Z0-9]{26}');
            Route::get('/jobs/{id}', [Http\JobsController::class, 'show'])->name('show')->where('id', '[A-Z0-9]{26}');
        });
        if (! Factory::hasMacro('currentWebhook')) {
            Factory::macro('currentWebhook', function (?string $deliveryId = null) {
                return $this->createPendingRequest()->withOptions([
                    'current_delivery_id' => $deliveryId ?? (string) Str::ulid(),
                ]);
            });
        }
        $this->callAfterResolving(Factory::class, function ($factory) {
            $factory->globalMiddleware(Webhooks\OutgoingRecorder::middleware());
        });
        $this->app['router']->aliasMiddleware('current.webhook', Webhooks\ObserveWebhook::class);
        $this->app['events']->listen(RequestHandled::class, function ($event) {
            try {
                $this->app->make(Broadcasts\AuthorizationRecorder::class)->record($event);
            } catch (\Throwable) {
            }
        });
        foreach ([RequestHandled::class => 'finish', JobQueued::class => 'queued'] as $event => $method) {
            $this->app['events']->listen($event, function ($event) use ($method) {
                try {
                    $this->app->make(Webhooks\WebhookRecorder::class)->$method($event);
                } catch (\Throwable) {
                }
            });
        }
        foreach ([JobPushed::class => 'pushed', JobQueued::class => 'queued', JobProcessing::class => 'processing', JobExceptionOccurred::class => 'exception', JobFailed::class => 'exception', JobAttempted::class => 'attempted', JobTimedOut::class => 'timedOut'] as $event => $method) {
            $this->app['events']->listen($event, function ($event) use ($method) {
                // Resolution failure is also isolated (for example a broken DB binding).
                try {
                    $this->app->make(Recorder::class)->$method($event);
                } catch (\Throwable) {
                    // Recorder diagnostics handle failures after successful resolution.
                }
            });
        }
        $this->commands([Console\PruneCommand::class, Console\InstallCommand::class]);
        $this->app->afterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('current:prune')->hourly());
    }
}
