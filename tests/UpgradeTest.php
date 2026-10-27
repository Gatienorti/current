<?php

namespace Current\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class UpgradeTest extends TestCase
{
    public function test_existing_history_and_configuration_survive_completed_or_interrupted_schema_upgrades(): void
    {
        $originalConfig = config('current.connection');
        $originalPath = config_path();
        foreach ([12, 13, 14, 15] as $completed) {
            $directory = sys_get_temp_dir().'/current-upgrade-'.bin2hex(random_bytes(6));
            mkdir($directory, 0700); mkdir($directory.'/migrations', 0700);
            touch($directory.'/history.sqlite');
            config()->set('database.connections.upgrade', ['driver' => 'sqlite', 'database' => $directory.'/history.sqlite', 'foreign_key_constraints' => true]);
            config()->set('current.connection', 'upgrade');
            $this->app->useConfigPath($directory);
            $customConfig = "<?php return ['path' => 'team-current', 'enabled' => true, 'capture_payload' => false];\n";
            file_put_contents($directory.'/current.php', $customConfig);
            $database = app('db')->connection('upgrade');
            try {
                foreach (array_slice(glob(dirname(__DIR__).'/database/migrations/*.php'), 0, $completed) as $migration) {
                    copy($migration, $directory.'/migrations/'.basename($migration));
                }
                $exit = Artisan::call('migrate', ['--database' => 'upgrade', '--path' => $directory.'/migrations', '--realpath' => true, '--force' => true]);
                $this->assertSame(0, $exit, Artisan::output());
                $job = (string) Str::ulid(); $attempt = (string) Str::ulid();
                $database->table('lens_jobs')->insert(['id' => $job, 'external_id' => 'old-dispatch', 'connection' => 'redis', 'queue' => 'legacy',
                    'job_class' => 'App\\Jobs\\LegacyJob', 'status' => 'completed', 'coverage' => 'observed_enqueue',
                    'first_observed_at' => time() - 60, 'observed_at' => time() - 50, 'expires_at' => time() + 3600, 'attempt_count' => 1]);
                $database->table('lens_attempts')->insert(['id' => $attempt, 'job_id' => $job, 'reported_attempt' => 1,
                    'worker_id' => 'legacy-worker', 'status' => 'completed', 'started_at' => time() - 55, 'finished_at' => time() - 50]);
                $database->table('lens_tags')->insert(['job_id' => $job, 'tag' => 'legacy:proof']);
                $database->table('lens_steps')->insert(['id' => (string) Str::ulid(), 'attempt_id' => $attempt, 'sequence' => 1,
                    'label' => 'Legacy step', 'status' => 'completed', 'started_at' => time() - 54, 'finished_at' => time() - 51]);
                $database->table('lens_meta')->insert(['key' => 'upgrade-proof', 'value' => 'keep']);
                $snapshots = [];
                foreach (['jobs', 'attempts', 'tags', 'steps', 'meta'] as $table) { $snapshots[$table] = $database->table('lens_'.$table)->get()->map(fn ($row) => (array) $row)->all(); }
                $appTables = Schema::connection('app')->getTableListing();
                $this->assertSame(0, Artisan::call('current:install'));
                $this->assertSame(16, $database->table('migrations')->count());
                $this->assertSame($customConfig, file_get_contents($directory.'/current.php'));
                foreach ($snapshots as $table => $rows) {
                    foreach ($rows as $before) {
                        $query = $database->table('lens_'.$table);
                        foreach ($before as $key => $value) { $query->where($key, $value); }
                        $this->assertTrue($query->exists(), 'Existing '.$table.' preserved after migration '.$completed);
                    }
                }
                $this->assertNull($database->table('lens_jobs')->where('id', $job)->value('dispatch_id'));
                $this->assertNull($database->table('lens_jobs')->where('id', $job)->value('cleared_at'));
                $this->assertTrue(Schema::connection('upgrade')->hasTable('lens_async_aliases'));
                $this->assertTrue(Schema::connection('upgrade')->hasTable('lens_job_actions'));
                $this->assertSame($appTables, Schema::connection('app')->getTableListing());
                $this->assertSame(0, Artisan::call('current:install'));
                $this->assertSame(16, $database->table('migrations')->count());
                $this->assertSame(1, $database->table('lens_jobs')->count());
            } finally {
                app('db')->purge('upgrade');
                foreach (glob($directory.'/migrations/*') as $file) { unlink($file); }
                rmdir($directory.'/migrations');
                foreach (glob($directory.'/*') as $file) { unlink($file); }
                rmdir($directory);
                config()->set('current.connection', $originalConfig);
                $this->app->useConfigPath($originalPath);
            }
        }
    }
}
