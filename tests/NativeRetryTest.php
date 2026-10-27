<?php

namespace Current\Tests;

use Current\Native\Retry;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Support\Facades\Gate;

final class NativeRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        Gate::define('viewCurrent', fn ($user) => true);
    }

    public function test_native_retry_uses_retained_payload_and_exact_dispatch_with_duplicate_post_protection(): void
    {
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $original = $this->store()->table('jobs')->sole();
        $this->assertTrue(app(Retry::class)->available($original));
        $url = '/current/jobs/'.$original->id.'/retry';
        $this->post($url)->assertStatus(419);
        $this->withSession(['_token' => 'retry-csrf'])->withHeader('X-CSRF-TOKEN', 'retry-csrf');
        $this->post($url)->assertForbidden();
        Gate::define('operateCurrent', fn ($user) => true);
        $response = $this->post($url)->assertRedirect();
        $retry = $this->store()->table('jobs')->where('id', '!=', $original->id)->sole();
        $this->assertSame($original->external_id, $retry->external_id);
        $this->assertNotSame($original->dispatch_id, $retry->dispatch_id);
        $response->assertRedirect(route('current.show', ['id' => $retry->id, 'watch' => 1]).'#execution-map');
        $this->post($url)->assertRedirect($response->headers->get('Location'));
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->get('/current/jobs/'.$original->id)->assertSee('View retry');
        $this->work();
        $this->assertFalse(app(Retry::class)->available($original));
        $this->assertTrue(app(Retry::class)->available($this->store()->table('jobs')->where('id', $retry->id)->first()));
    }
}
