<?php

namespace HorizonLens\Tests;

use HorizonLens\Prototype\Jobs\OrderInvestigationJob;
use HorizonLens\Prototype\Jobs\PaymentJob;
use HorizonLens\SourceOutline;
use Mockery;
use PhpParser\ParserFactory;

final class SourceOutlineCacheTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function expectParses(int $count): void
    {
        $factory = Mockery::mock(ParserFactory::class)->makePartial();
        $factory->shouldReceive('createForNewestSupportedVersion')->times($count)->passthru();
        $this->app->instance(ParserFactory::class, $factory);
    }

    private function fixture(): array
    {
        $class = 'CurrentSourceFixture'.bin2hex(random_bytes(8));
        $file = storage_path('data/'.$class.'.php');
        $source = '<?php class '.$class.' { public function handle() { first_call(); second_call(); } }';
        file_put_contents($file, $source);
        $this->files[] = $file;
        require $file;
        config()->set('horizon-lens.source_roots', [dirname($file)]);

        return [$class, $file, $source];
    }

    public function test_real_retries_reuse_one_parse_and_preserve_attempt_evidence(): void
    {
        $this->expectParses(1);
        dispatch(new OrderInvestigationJob);
        $this->work(3);
        $attempts = $this->store()->table('attempts')->get();
        $this->assertCount(3, $attempts);
        $this->assertCount(1, $attempts->pluck('source_hash')->unique());
        $this->assertCount(1, $attempts->pluck('source_operations')->unique());
        $this->assertNotEmpty($attempts->first()->source_operations);
        $this->assertSame(36, $this->store()->table('steps')->count());
        $this->assertSame('failed', $this->store()->table('jobs')->first()->status);
    }

    public function test_content_changes_invalidate_even_with_identical_size_and_timestamp(): void
    {
        [$class, $file, $source] = $this->fixture();
        $this->expectParses(2);
        $outline = app(SourceOutline::class);
        $first = $outline->capture($class);
        $this->assertSame($first, app(SourceOutline::class)->capture($class));
        $mtime = filemtime($file);
        $changed = str_replace('first_call', 'other_call', $source);
        $this->assertSame(strlen($source), strlen($changed));
        file_put_contents($file, $changed);
        touch($file, $mtime);
        $second = $outline->capture($class);
        $this->assertNotSame($first['source_hash'], $second['source_hash']);
        $this->assertStringContainsString('other_call()', $second['source_operations']);
        $this->assertStringNotContainsString('first_call()', $second['source_operations']);
        config()->set('horizon-lens.source_revision', 'next-release');
        $this->assertSame('next-release', $outline->capture($class)['source_revision']);
    }

    public function test_current_roots_size_and_operation_limits_apply_to_cache_hits(): void
    {
        [$class, $file] = $this->fixture();
        $this->expectParses(2);
        $outline = app(SourceOutline::class);
        $this->assertCount(2, json_decode($outline->capture($class)['source_operations'], true));
        config()->set('horizon-lens.max_source_operations', 1);
        $this->assertCount(1, json_decode($outline->capture($class)['source_operations'], true));
        config()->set('horizon-lens.source_roots', []);
        $this->assertSame([], $outline->capture($class));
        config()->set('horizon-lens.source_roots', [dirname($file)]);
        config()->set('horizon-lens.max_source_bytes', 10);
        $this->assertSame([], $outline->capture($class));
        config()->set('horizon-lens.max_source_bytes', 262144);
        $this->assertCount(1, json_decode($outline->capture($class)['source_operations'], true));
    }

    public function test_lru_capacity_and_disabling_cache_bound_retained_entries(): void
    {
        [$a] = $this->fixture();
        [$b] = $this->fixture();
        [$c] = $this->fixture();
        $this->expectParses(6);
        config()->set('horizon-lens.source_cache_entries', 2);
        $outline = app(SourceOutline::class);
        $aResult = $outline->capture($a);
        $bResult = $outline->capture($b);
        $this->assertSame($aResult, $outline->capture($a));
        $this->assertNotEmpty($outline->capture($c));
        $this->assertSame($bResult, $outline->capture($b)); // B was least recently used.
        config()->set('horizon-lens.source_cache_entries', 0);
        $this->assertSame($aResult, $outline->capture($a));
        $this->assertSame($aResult, $outline->capture($a));
    }

    public function test_json_byte_budget_evicts_even_below_the_entry_limit(): void
    {
        $this->expectParses(17);
        config()->set('horizon-lens.source_cache_entries', 128);
        config()->set('horizon-lens.max_source_operations', 1000);
        $outline = app(SourceOutline::class);
        $firstClass = null;
        $firstResult = null;
        for ($i = 0; $i < 16; $i++) {
            [$class, $file] = $this->fixture();
            $calls = str_repeat(str_repeat('a', 145).'();', 1000);
            file_put_contents($file, '<?php class '.$class.' { public function handle() { '.$calls.' } }');
            $result = $outline->capture($class);
            $this->assertCount(1000, json_decode($result['source_operations'], true));
            $firstClass ??= $class;
            $firstResult ??= $result;
        }
        $this->assertSame($firstResult, $outline->capture($firstClass));
    }

    public function test_parse_failures_can_recover_and_deleted_files_never_return_cached_data(): void
    {
        [$class, $file, $source] = $this->fixture();
        $this->expectParses(2);
        $outline = app(SourceOutline::class);
        file_put_contents($file, '<?php syntax error !');
        $this->assertSame([], $outline->capture($class));
        file_put_contents($file, $source);
        $this->assertNotEmpty($outline->capture($class));
        unlink($file);
        $this->assertSame([], $outline->capture($class));
    }

    public function test_analysis_failure_does_not_stop_actual_job_execution(): void
    {
        $factory = Mockery::mock(ParserFactory::class);
        $factory->shouldReceive('createForNewestSupportedVersion')->once()->andThrow(new \RuntimeException('parser unavailable'));
        $this->app->instance(ParserFactory::class, $factory);
        dispatch(new PaymentJob('success'));
        $this->work();
        $this->assertSame('completed', $this->store()->table('jobs')->first()->status);
        $this->assertNull($this->store()->table('attempts')->first()->source_hash);
        $this->assertSame(4, $this->store()->table('steps')->count());
    }
}
