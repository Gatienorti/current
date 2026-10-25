<?php

namespace HorizonLens\AdaptLab;

use App\Jobs\SendPushNotificationJob;
use App\Services\GarminService;
use App\Services\PushNotificationService;
use HorizonLens\Lens;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mockery;
use RuntimeException;

final class SandboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Explicit lab-only exports; private job state and serialized commands stay untouched.
        config()->set('horizon-lens.job_context', static function (object $job): array {
            $context = ['metadata' => ['integration' => 'ADAPT isolated lab', 'job_class' => $job::class]];
            if ($job instanceof SendPushNotificationJob) {
                $context['arguments'] = ['notification_id' => $job->notificationId];
            }

            return $context;
        });
        Http::preventStrayRequests();
        $push = Mockery::mock(PushNotificationService::class);
        $push->shouldReceive('sendForNotification')->andReturnUsing(function ($notification) {
            return Lens::step('Mock push transport', function () use ($notification) {
                Lens::step('Build synthetic notification', fn () => true);
                $scenario = $notification->data['lens_scenario'] ?? 'success';
                if ($scenario === 'failure' || ($scenario === 'retry' && app('horizon-lens.active-job')->attempts() === 1)) {
                    throw new RuntimeException('Synthetic push failure; token=never-persist-this');
                }

                return true;
            });
        });
        $this->app->instance(PushNotificationService::class, $push);
        $garmin = Mockery::mock(GarminService::class);
        $garmin->shouldReceive('getActivityDetail')->andReturnNull();
        $this->app->instance(GarminService::class, $garmin);
        $this->app['events']->listen(JobProcessing::class, function ($event) {
            $this->app->instance('horizon-lens.active-job', $event->job);
        });
    }

    public function boot(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/__lens-lab/login', fn () => response('<!doctype html><title>ADAPT Lens lab login</title><h1>ADAPT · isolated Lens lab</h1><p>Synthetic account: operator@lens.invalid / lens-local-only</p><form method="post"><input type="hidden" name="_token" value="'.csrf_token().'"><label>Email <input name="email" value="operator@lens.invalid"></label><label>Password <input type="password" name="password" value="lens-local-only"></label><button>Sign in to lab</button></form>'));
            Route::post('/__lens-lab/login', function (Request $request) {
                abort_unless(Auth::attempt($request->only('email', 'password')), 403);
                $request->session()->regenerate();

                return redirect('/horizon-lens');
            });
        });
    }
}
