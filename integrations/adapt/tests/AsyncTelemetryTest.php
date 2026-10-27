<?php

namespace Current\AdaptTests;

use App\Jobs\SendPushNotificationJob;
use Current\AdaptLab\Fixtures;
use Current\Store;
use Current\Telemetry\Transport;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

final class AsyncTelemetryTest extends TestCase
{
    public function createApplication()
    {
        $app = bootAdaptLab(sys_get_temp_dir().'/current-adapt-async-package-'.bin2hex(random_bytes(6)));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_supported_opt_in_collects_actual_adapt_retries_and_renders_the_existing_ui(): void
    {
        $binary = dirname(__DIR__, 3).'/collector/current-collector';
        if (! is_executable($binary)) {
            $this->markTestSkipped('Build the optional Go collector.');
        }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('current:install', ['--force' => true]), Artisan::output());
        $fixtures = Fixtures::seed();
        config()->set(['current.telemetry.driver' => 'redis', 'current.telemetry.binary' => $binary]);
        $transport = app(Transport::class);
        try {
            dispatch((new SendPushNotificationJob($fixtures['notifications']['retry']))->onQueue('notifications'));
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--once' => true, '--sleep' => 0]));
            Carbon::setTestNow(now()->addSeconds(61));
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--once' => true, '--sleep' => 0]));
            Carbon::setTestNow();
            $store = app(Store::class);
            $this->assertSame(0, $store->table('jobs')->count());
            $this->assertStringNotContainsString('never-persist-this', json_encode($transport->client()->xRange('events', '-', '+')));
            $this->assertSame(0, Artisan::call('current:collect', ['--once' => true]), Artisan::output());
            $job = $store->table('jobs')->sole();
            $this->assertSame('completed', $job->status);
            $this->assertSame(2, $job->attempt_count);
            $this->assertSame('captured', $job->arguments_state);
            $this->assertSame('captured', $job->metadata_state);
            $this->assertSame(['released', 'completed'], $store->table('attempts')->orderBy('started_at')->pluck('status')->all());
            $this->assertSame(4, $store->table('steps')->count());
            $this->assertSame(2, $store->table('steps')->whereNotNull('parent_id')->count());
            $this->assertStringNotContainsString('never-persist-this', json_encode($store->table('attempts')->get()));
            $this->actingAs($fixtures['users']['operator'])->get('/current/jobs/'.$job->id)->assertOk()->assertSee('Mock push transport')->assertSee('Metadata');
            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
            $s = $transport->settings();
            foreach ($transport->client()->keys('*') as $key) {
                $transport->client()->del(substr($key, strlen($s['prefix'])));
            }
            @unlink(config('database.connections.lens.database').'.collector.lock');
            \Mockery::close();
        }
    }
}
