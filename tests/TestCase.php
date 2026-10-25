<?php

namespace HorizonLens\Tests;

use HorizonLens\Http\Authorize;
use HorizonLens\Store;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

abstract class TestCase extends LaravelTestCase
{
    private array $databaseFiles = [];

    public function createApplication()
    {
        $app = require dirname(__DIR__).'/prototype/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app', 'lens'] as $connection) {
            $path = storage_path('data/test-'.$connection.'-'.Str::ulid().'.sqlite');
            touch($path);
            $this->databaseFiles[] = $path;
            config()->set("database.connections.{$connection}.database", $path);
            app('db')->purge($connection);
        }
        config()->set('database.redis.options.prefix', 'lens_test_'.Str::ulid().':');
        config()->set('horizon.prefix', 'lens_test_horizon_'.Str::ulid().':');
        // Horizon copies this prefix into its connection during provider boot.
        // Update that copy too, so tests cannot write into the preview's metrics.
        config()->set('database.redis.horizon.options.prefix', config('horizon.prefix'));
        app('redis')->purge('default');
        app('redis')->purge('horizon');
        config()->set('horizon-lens.diagnostic_path', storage_path('data/test-health-'.Str::ulid().'.json'));
        $this->databaseFiles[] = config('horizon-lens.diagnostic_path');
        (require dirname(__DIR__).'/database/migrations/2026_10_25_000001_create_horizon_lens_tables.php')->up();
        (require dirname(__DIR__).'/database/migrations/2026_10_25_000002_add_silenced_classification.php')->up();
        (require dirname(__DIR__).'/database/migrations/2026_10_25_000003_add_job_details.php')->up();
        Schema::connection('app')->create('failed_jobs', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $this->app->bind(Authorize::class, fn () => new Authorize);
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach (['app', 'lens'] as $connection) {
                $this->app['db']->purge($connection);
            }
        }
        parent::tearDown();
        foreach ($this->databaseFiles as $path) {
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    protected function work(int $count = 1): void
    {
        $exit = Artisan::call('queue:work', [
            'connection' => 'redis', '--queue' => 'lens-demo', '--stop-when-empty' => true,
            '--max-jobs' => $count, '--sleep' => 0, '--tries' => 3, '--timeout' => 2,
        ]);
        $this->assertSame(0, $exit);
    }

    protected function store(): Store
    {
        return app(Store::class);
    }
}
