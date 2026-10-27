<?php

namespace Current\Tests;

use Current\Http\Authorize;
use HorizonLens\Lens;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

final class InstallTest extends TestCase
{
    public function test_existing_config_class_names_resolve_after_the_current_namespace_is_loaded(): void
    {
        $this->assertTrue(class_exists(Authorize::class));
        $this->assertTrue(class_exists('HorizonLens\\Http\\Authorize'));
        $this->assertSame(Authorize::class, (new \ReflectionClass('HorizonLens\\Http\\Authorize'))->getName());
        $this->assertSame('preserved', Lens::step('Upgrade check', fn () => 'preserved'));
    }

    public function test_installer_is_repeatable_and_only_migrates_its_own_connection(): void
    {
        $directory = sys_get_temp_dir().'/current-install-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $oldPath = config_path();
        $this->app->useConfigPath($directory);
        config()->set('current.connection', 'current');
        config()->set('database.connections.current.database', $directory.'/history.sqlite');
        app('db')->purge('current');
        try {
            $this->assertSame(0, Artisan::call('current:install'));
            $this->assertFileExists($directory.'/current.php');
            $this->assertTrue(Schema::connection('current')->hasColumn('lens_jobs', 'arguments_json'));
            app('db')->connection('current')->table('lens_meta')->insert(['key' => 'installation-proof', 'value' => 'keep']);
            file_put_contents($directory.'/current.php', "<?php return ['path' => 'custom-current'];");
            $this->assertSame(0, Artisan::call('current:install'));
            $this->assertSame('keep', app('db')->connection('current')->table('lens_meta')->where('key', 'installation-proof')->value('value'));
            $this->assertStringContainsString('custom-current', file_get_contents($directory.'/current.php'));
            $this->assertFalse(Schema::connection('app')->hasTable('migrations'));
            $this->assertSame(17, app('db')->connection('current')->table('migrations')->count());
            $this->assertSame(0, Artisan::call('current:prune'));
            $this->assertSame(0, Artisan::call('lens:prune'));
            $events = app(Schedule::class)->events();
            $this->assertCount(1, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'current:prune')));
        } finally {
            $this->app->useConfigPath($oldPath);
            app('db')->purge('current');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_ordinary_application_migrations_do_not_repeat_dedicated_history_migrations(): void
    {
        $directory = sys_get_temp_dir().'/current-install-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        $oldPath = config_path(); $oldConnection = config('current.connection');
        $this->app->useConfigPath($directory);
        config()->set('current.connection', 'current');
        config()->set('database.connections.current.database', $directory.'/history.sqlite');
        app('db')->purge('current');
        try {
            $this->assertSame(0, Artisan::call('current:install'));
            app('db')->connection('current')->table('lens_meta')->insert(['key' => 'application-upgrade-proof', 'value' => 'keep']);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'app', '--force' => true]));
            $this->assertSame('keep', app('db')->connection('current')->table('lens_meta')->where('key', 'application-upgrade-proof')->value('value'));
            $this->assertSame(17, app('db')->connection('current')->table('migrations')->count());
            $this->assertSame(0, app('db')->connection('app')->table('migrations')->where('migration', 'like', '%horizon_lens%')->count());
        } finally {
            $this->app->useConfigPath($oldPath); config()->set('current.connection', $oldConnection);
            app('db')->purge('current');
            foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
        }
    }

    public function test_failed_configuration_publication_does_not_report_success_or_start_migrations(): void
    {
        $directory = sys_get_temp_dir().'/current-install-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        file_put_contents($directory.'/config-blocker', 'keep');
        $oldPath = config_path(); $oldConnection = config('current.connection');
        $this->app->useConfigPath($directory.'/config-blocker');
        config()->set('current.connection', 'current');
        config()->set('database.connections.current.database', $directory.'/history.sqlite');
        app('db')->purge('current');
        try {
            $exit = Artisan::call('current:install'); $output = Artisan::output();
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('Could not publish', $output);
            $this->assertStringNotContainsString('Published config/current.php', $output);
            $this->assertSame('keep', file_get_contents($directory.'/config-blocker'));
            $this->assertFalse(Schema::connection('current')->hasTable('migrations'));
        } finally {
            $this->app->useConfigPath($oldPath); config()->set('current.connection', $oldConnection);
            app('db')->purge('current');
            foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
        }
    }
}
