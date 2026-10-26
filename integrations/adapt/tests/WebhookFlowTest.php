<?php

namespace HorizonLens\AdaptTests;

use HorizonLens\AdaptLab\Fixtures;
use HorizonLens\Store;
use HorizonLens\Tests\Concerns\ChecksWebhookFlow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once dirname(__DIR__, 3).'/tests/Concerns/ChecksWebhookFlow.php';
require_once dirname(__DIR__, 3).'/prototype/Jobs/OutgoingWebhookJob.php';

final class WebhookFlowTest extends TestCase
{
    use ChecksWebhookFlow;

    public function createApplication()
    {
        $app = bootAdaptLab(sys_get_temp_dir().'/current-adapt-flow-'.bin2hex(random_bytes(6)));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $fixtures = Fixtures::seed();
        $this->actingAs($fixtures['users']['operator']);
    }

    protected function tearDown(): void
    {
        // Both HTTP attempts were intercepted by the synthetic job's fakes.
        Http::assertSentCount(2);
        \Mockery::close();
        parent::tearDown();
    }

    private function store(): Store
    {
        return app(Store::class);
    }
}
