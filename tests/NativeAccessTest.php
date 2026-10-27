<?php

namespace Current\Tests;

use Current\HorizonAdapter;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Support\Facades\Gate;

final class NativeAccessTest extends TestCase
{
    public function test_native_access_requires_its_own_explicit_gate(): void
    {
        config()->set('current.execution_backend', 'native');
        $this->get('/current')->assertForbidden();
        Gate::define('viewCurrent', fn ($user) => false);
        $this->get('/current/monitoring')->assertForbidden();
        Gate::define('viewCurrent', fn ($user) => $user->is_admin);
        $this->app->bind(HorizonAdapter::class, fn () => throw new \RuntimeException('Native UI must not resolve Horizon'));
        $this->get('/current')->assertOk()->assertSee('Worker status unavailable')->assertSee('Jobs Per Minute')->assertSee('Captured completed attempts / minute');
        $this->get('/current/monitoring')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get('/current')->assertForbidden();
    }

    public function test_native_pages_do_not_require_horizon_and_retry_requires_operator_access(): void
    {
        dispatch(new PaymentJob('failure'));
        $this->work(3);
        $job = $this->store()->table('jobs')->sole();
        config()->set('current.execution_backend', 'native');
        Gate::define('viewCurrent', fn ($user) => true);
        // A Horizon route must not be required even for recorded job details.
        $this->app['router']->getRoutes()->getRoutesByName()['horizon.index']->name('unavailable-horizon');
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->assertFalse($this->app['router']->has('horizon.index'));
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertDontSee('Retry job')->assertSee('Charge payment');
        foreach (['queues', 'metrics', 'metrics/jobs', 'batches', 'silenced'] as $path) {
            $this->get('/current/'.$path)->assertOk();
        }
        $this->post('/current/jobs/'.$job->id.'/retry')->assertStatus(419);
        $this->withSession(['_token' => 'native-test-csrf'])->withHeader('X-CSRF-TOKEN', 'native-test-csrf');
        $this->post('/current/jobs/'.$job->id.'/retry')->assertForbidden();
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$job->id)->exists());
        $this->get('/current')->assertSee('href="'.route('current.metrics').'"', false);
        config()->set('current.execution_backend', 'typo');
        $this->get('/current')->assertForbidden();
    }

    public function test_native_refresh_keeps_unsupported_status_explicit(): void
    {
        config()->set('current.execution_backend', 'native');
        Gate::define('viewCurrent', fn ($user) => true);
        $this->withHeader('X-Current-Refresh', '1')->get('/current')
            ->assertOk()->assertHeader('X-Current-Refresh', '1')->assertSee('Worker status unavailable');
        $this->get('/current/metrics')->assertOk()->assertHeader('X-Current-Refresh', '1');
    }
}
