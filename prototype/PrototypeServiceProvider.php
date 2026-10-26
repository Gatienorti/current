<?php

namespace HorizonLens\Prototype;

use App\Jobs\WarmVehicleBasicCache;
use App\Services\VinLookupService;
use HorizonLens\Http\Authorize;
use HorizonLens\Prototype\Jobs\OrderInvestigationJob;
use HorizonLens\Prototype\Jobs\PaymentJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

final class PrototypeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->usePublicPath(__DIR__.'/public');
    }

    public function boot(): void
    {
        config()->set('horizon-lens.connection', 'lens');
        config()->set('horizon-lens.tag_prefixes', ['order:', 'scenario:']);
        config()->set('horizon-lens.source_roots', [__DIR__, base_path('../tests'), '/opt/vehify/app']);
        config()->set('horizon-lens.message_sanitizer', static fn (string $message) => preg_replace('/(token|password|secret|api_key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $message));
        config()->set('horizon-lens.middleware', [DemoAccess::class]);
        // Package routes have already registered, so aliasing is not sufficient.
        // Bind only this isolated lab's request authorization; package default stays strict.
        $this->app->bind(Authorize::class, DemoAccess::class);
        Route::middlewareGroup('horizon', ['web', DemoAccess::class]);
        Horizon::auth(fn ($request) => (bool) $request->user()?->is_admin);
        Gate::define('viewHorizon', fn ($user) => (bool) $user?->is_admin);
        Http::preventStrayRequests();
        config()->set('horizon-lens.webhooks.enabled', true);
        WebhookDemo::register();
        config()->set('horizon-lens.webhooks.outgoing', [
            'enabled' => true,
            'destinations' => ['demo-receiver' => 'https://receiver.example.test/webhook'],
        ]);
        Artisan::command('lens:outgoing-demo', function () {
            config()->set('horizon-lens.webhooks.capture_context', true);
            config()->set('horizon-lens.webhooks.response_context', fn ($response) => ['http_status' => $response->getStatusCode()]);
            foreach (['success', 'retry', 'connection'] as $scenario) {
                dispatch(new Jobs\OutgoingWebhookJob($scenario));
                app('queue.worker')->runNextJob('redis', 'lens-outgoing-demo', new WorkerOptions(sleep: 0, maxTries: 1));
            }
            $this->info('Synthetic outgoing attempts recorded using HTTP fakes; no external requests sent.');
        });
        Route::get('/', fn () => redirect()->route('horizon-lens.index'));
        Artisan::command('lens:vehify-demo', function () {
            $lookup = \Mockery::mock(VinLookupService::class);
            $lookup->shouldReceive('basic')->once()->with('SYNTHETIC-LENS')->andReturnNull();
            app()->instance(VinLookupService::class, $lookup);
            $job = new WarmVehicleBasicCache('SYNTHETIC-LENS');
            $job->onQueue('lens-vehify-safe');
            Queue::pushOn('lens-vehify-safe', $job);
            app('queue.worker')->runNextJob('redis', 'lens-vehify-safe', new WorkerOptions(sleep: 0, maxTries: 1));
            \Mockery::close();
            $this->info('Executed the actual Vehify cache-warming job with a mocked lookup; no host database or API calls.');
        });
        Artisan::command('lens:live-retry-demo', function () {
            dispatch(new Jobs\LiveRetryJob);
            app('queue.worker')->runNextJob('redis', 'lens-live-retry', new WorkerOptions(sleep: 0, maxTries: 1));
            $this->info('Synthetic live retry failure recorded.');
        });
        Artisan::command('lens:demo', function () {
            foreach (['success', 'failure', 'retry', 'release', 'untraced'] as $index => $scenario) {
                dispatch(new PaymentJob($scenario, 'demo-'.(1041 + $index)));
            }
            $this->info('Enqueued five synthetic jobs on the isolated lens-demo queue.');
        });
        Artisan::command('lens:details-demo', function () {
            dispatch(new OrderInvestigationJob);
            $this->info('Enqueued a synthetic 12-step job with explicit Args/Metadata snapshots on lens-demo.');
        });
    }
}
