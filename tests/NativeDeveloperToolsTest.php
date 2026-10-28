<?php

namespace Current\Tests;

use Current\Native\DeveloperListener;
use Current\Native\SourceWatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeDeveloperToolsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/current-watch-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_polling_detects_same_size_edits_additions_and_removals(): void
    {
        $path = $this->directory.'/source.php';
        file_put_contents($path, 'before');
        $time = filemtime($path);
        $watcher = new SourceWatcher($this->directory, ['source.php', 'new.php']);
        $initial = $watcher->snapshot();
        file_put_contents($path, 'edited'); touch($path, $time);
        $edited = $watcher->snapshot();
        $this->assertNotSame($initial, $edited);
        file_put_contents($this->directory.'/new.php', 'new');
        $added = $watcher->snapshot();
        $this->assertNotSame($edited, $added);
        unlink($this->directory.'/new.php');
        $this->assertSame($edited, $watcher->snapshot());
        unlink($path);
        $this->assertNotSame($edited, $watcher->snapshot());
    }

    public function test_source_scope_rejects_traversal_wildcards_symlinks_and_oversized_files(): void
    {
        foreach (['../outside', '/absolute', 'app/**/*.php', 'app/./file', 'app\\file'] as $path) {
            try { new SourceWatcher($this->directory, [$path]); $this->fail('Unsafe watch path accepted: '.$path); }
            catch (RuntimeException $e) { $this->assertStringContainsString('relative files or directories', $e->getMessage()); }
        }
        file_put_contents($this->directory.'/source.php', 'test');
        symlink($this->directory.'/source.php', $this->directory.'/link.php');
        try { (new SourceWatcher($this->directory, ['link.php']))->snapshot(); $this->fail('Symlink accepted'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('symlinks', $e->getMessage()); }
        $file = fopen($this->directory.'/large.php', 'wb'); ftruncate($file, 8388609); fclose($file);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('8 MiB per file');
        (new SourceWatcher($this->directory, ['large.php']))->snapshot();
    }

    public function test_generated_bootstrap_cache_writes_do_not_restart_source_watchers(): void
    {
        mkdir($this->directory.'/bootstrap/cache', 0700, true);
        file_put_contents($this->directory.'/bootstrap/app.php', 'source');
        $watcher = new SourceWatcher($this->directory, ['bootstrap']);
        $initial = $watcher->snapshot();
        foreach (['packages.php', 'services.php', 'config.php'] as $file) {
            file_put_contents($this->directory.'/bootstrap/cache/'.$file, 'generated');
            $this->assertSame($initial, $watcher->snapshot());
        }
        $explicit = new SourceWatcher($this->directory, ['bootstrap/cache']);
        $before = $explicit->snapshot();
        file_put_contents($this->directory.'/bootstrap/cache/packages.php', 'regenerated');
        $this->assertNotSame($before, $explicit->snapshot());
        file_put_contents($this->directory.'/bootstrap/app.php', 'changed');
        $this->assertNotSame($initial, $watcher->snapshot());
    }

    public function test_listener_drains_owned_child_before_restarting_and_cleans_up_on_stop(): void
    {
        if (! function_exists('pcntl_signal')) { $this->markTestSkipped('Requires PCNTL in the fixture PHP process.'); }
        file_put_contents($this->directory.'/source.php', 'before');
        file_put_contents($this->directory.'/child.php', <<<'PHP'
<?php
$lock = fopen(__DIR__.'/child.lock', 'c');
if (! flock($lock, LOCK_EX | LOCK_NB)) { echo "overlap\n"; exit(2); }
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use ($lock) {
    echo "draining\n"; fflush(STDOUT); usleep(150000);
    flock($lock, LOCK_UN); echo "stopped\n"; fflush(STDOUT); exit(0);
});
echo "ready\n"; fflush(STDOUT);
$end = microtime(true) + 5;
while (microtime(true) < $end) { usleep(10000); }
exit(3);
PHP);
        $listener = new DeveloperListener;
        $output = ''; $changed = 0; $edited = false;
        $exit = $listener->run([PHP_BINARY, $this->directory.'/child.php'], $this->directory,
            new SourceWatcher($this->directory, ['source.php']), 100, 1,
            function ($buffer) use (&$output, &$edited, $listener) {
                $output .= $buffer;
                if (! $edited && str_contains($output, "ready\n")) {
                    file_put_contents($this->directory.'/source.php', 'after'); $edited = true;
                }
                if (substr_count($output, "ready\n") === 2) { $listener->stop(); }
            }, function () use (&$changed) { $changed++; });
        $this->assertSame(0, $exit, $output);
        $this->assertSame(1, $changed);
        $this->assertSame(2, substr_count($output, "ready\n"), $output);
        $this->assertSame(2, substr_count($output, "stopped\n"), $output);
        $this->assertStringNotContainsString('overlap', $output);
        $this->assertStringContainsString("ready\ndraining\nstopped\nready\n", $output);
    }

    public function test_listener_stops_its_child_when_a_later_scan_fails(): void
    {
        if (! function_exists('pcntl_signal')) { $this->markTestSkipped('Requires PCNTL in the fixture PHP process.'); }
        file_put_contents($this->directory.'/source.php', 'source');
        file_put_contents($this->directory.'/child.php', '<?php pcntl_async_signals(true); pcntl_signal(SIGTERM, function () { echo "stopped"; exit; }); echo "ready"; fflush(STDOUT); while (true) { usleep(10000); }');
        $output = ''; $listener = new DeveloperListener;
        try {
            $listener->run([PHP_BINARY, $this->directory.'/child.php'], $this->directory,
                new SourceWatcher($this->directory, ['source.php']), 100, 1,
                function ($buffer) use (&$output) {
                    $output .= $buffer;
                    if (str_contains($buffer, 'ready')) {
                        unlink($this->directory.'/source.php');
                        symlink($this->directory.'/child.php', $this->directory.'/source.php');
                    }
                }, fn () => null);
            $this->fail('Scan failure was hidden.');
        } catch (RuntimeException $e) { $this->assertStringContainsString('symlinks', $e->getMessage()); }
        $this->assertStringContainsString('stopped', $output);
    }
}
