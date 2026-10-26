<?php

namespace HorizonLens\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

final class InstallTest extends TestCase
{
    public function test_installer_is_repeatable_and_only_migrates_its_own_connection(): void
    {
        $directory = sys_get_temp_dir().'/current-install-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $oldPath = config_path();
        $this->app->useConfigPath($directory);
        config()->set('horizon-lens.connection', 'current');
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
            $this->assertSame(11, app('db')->connection('current')->table('migrations')->count());
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
}
