<?php

namespace HorizonLens\Tests;

use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Mockery;

final class QueuesTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = sys_get_temp_dir().'/current-routing-'.bin2hex(random_bytes(6));
        mkdir($this->source);
        file_put_contents($this->source.'/Jobs.php', <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
class ClassifyJob implements ShouldQueue {
    public function __construct() { $this->onQueue('classifications'); }
    public function handle() { throw new \Exception('Must never run'); }
}
class ImportJob implements ShouldQueue { public $queue = 'route-imports'; }
class EnrichJob implements ShouldQueue {
    public function __construct() { $this->queue = 'route-enrichment'; }
}
class DefaultJob implements ShouldQueue {}
class DynamicJob implements ShouldQueue {
    public function __construct($queue) { $this->onQueue($queue); }
}
class OtherConnectionJob implements ShouldQueue { public $queue = 'classifications'; public $connection = 'other'; }
class Unrelated { public function handle() {} }
DefaultJob::dispatch()->onQueue('route-imports');
class RuntimeConnectionJob implements ShouldQueue {}
RuntimeConnectionJob::dispatch()->onQueue('classifications')->onConnection($runtimeConnection);
CODE);
        config()->set('horizon-lens.source_roots', [$this->source]);
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.queue', 'default');
        config()->set('horizon.env', 'testing');
        config()->set('horizon.defaults', ['classify' => ['connection' => 'redis', 'queue' => ['classifications'], 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 70]]);
        config()->set('horizon.environments', ['testing' => [
            'routes' => ['connection' => 'redis', 'queue' => ['route-imports', 'route-enrichment'], 'balance' => 'auto', 'minProcesses' => 2, 'maxProcesses' => 10],
            'default' => ['connection' => 'redis', 'queue' => ['default'], 'balance' => false, 'maxProcesses' => 5],
        ]]);
        $repo = Mockery::mock(SupervisorRepository::class);
        $repo->shouldReceive('all')->andReturn([]);
        $this->app->instance(SupervisorRepository::class, $repo);
    }

    protected function tearDown(): void
    {
        unlink($this->source.'/Jobs.php');
        rmdir($this->source);
        parent::tearDown();
    }

    public function test_architecture_uses_declarations_and_worker_ranges_without_job_history_or_running_workers(): void
    {
        Schema::connection('lens')->drop('lens_jobs');
        $response = $this->get('/horizon-lens/queues')->assertOk()->assertSee('ClassifyJob')->assertSee('ImportJob')
            ->assertSee('EnrichJob')->assertSee('DefaultJob')->assertSee('1–70 workers')->assertSee('5 workers')
            ->assertSee('Min 2 / queue · Max 10 total')->assertSee('Dynamic / inherited')->assertSee('other · classifications')
            ->assertDontSee('None observed')->assertDontSee('Retained')->assertDontSee('Unrelated');
        $tables = collect($response->viewData('supervisorTables'))->keyBy('name');
        $this->assertCount(3, $tables);
        $this->assertSame('App\\Jobs\\ClassifyJob', $tables['classify']['queues'][0]['classes'][0]['class']);
        $this->assertNull($tables['classify']['allocated']);
        $this->assertCount(1, $tables['classify']['queues'][0]['classes']);
        $this->assertTrue($response->viewData('unmapped')['App\\Jobs\\RuntimeConnectionJob']['dynamic']);
        $this->assertContains('App\\Jobs\\DefaultJob', array_column($tables['routes']['queues'][0]['classes'], 'class'));
    }

    public function test_environment_wildcards_and_source_failures_are_explicit(): void
    {
        config()->set('horizon.env', 'production-east');
        config()->set('horizon.environments', ['production-*' => []]);
        config()->set('horizon-lens.source_roots', [$this->source.'/missing']);
        $this->get('/horizon-lens/queues')->assertOk()->assertSee('production-east')
            ->assertSee('classify')->assertSee('Some source files could not be inspected');
    }
}
