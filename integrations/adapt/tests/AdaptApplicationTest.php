<?php

namespace Current\AdaptTests;

use App\Jobs\FetchGarminActivityDetail;
use App\Jobs\SendPushNotificationJob;
use App\Jobs\TrainingPlan\CreateTrainingPhasesJob;
use Current\AdaptLab\Fixtures;
use Current\HorizonAdapter;
use Current\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

final class AdaptApplicationTest extends TestCase
{
    private array $fixtures;

    public function createApplication()
    {
        $app = bootAdaptLab(sys_get_temp_dir().'/lens-adapt-test-'.bin2hex(random_bytes(6)));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('current:install', ['--force' => true]), Artisan::output());
        $this->fixtures = Fixtures::seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Http::assertNothingSent();
        \Mockery::close();
        parent::tearDown();
    }

    private function work(string $queue): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--sleep' => 0]));
    }

    private function store(): Store
    {
        return app(Store::class);
    }

    public function test_normal_providers_session_auth_and_staff_gate_protect_all_lens_pages(): void
    {
        foreach (['AppServiceProvider', 'ActionServiceProvider', 'AuthServiceProvider', 'HorizonServiceProvider'] as $provider) {
            $this->assertTrue($this->app->providerIsLoaded('App\\Providers\\'.$provider));
        }
        foreach (['/current', '/current/monitoring', '/current/metrics/queues', '/current/assets/lens.css'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/__lens-lab/login', ['email' => 'member@lens.invalid', 'password' => 'lens-local-only'])->assertRedirect('/current');
        $this->assertAuthenticatedAs($this->fixtures['users']['member']);
        $this->get('/current')->assertForbidden();
        $this->assertFalse(Gate::allows('viewHorizon'));
        $this->post('/__lens-lab/login', ['email' => 'operator@lens.invalid', 'password' => 'lens-local-only'])->assertRedirect('/current');
        $this->assertAuthenticatedAs($this->fixtures['users']['operator']);
        $this->assertTrue(Gate::allows('viewHorizon'));
        foreach (['/current', '/current/monitoring', '/current/metrics/jobs', '/current/metrics/queues', '/current/batches', '/current/schedule', '/current/queues', '/current/jobs/pending', '/current/jobs/completed', '/current/jobs/failed', '/current/silenced'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_schedule_reads_actual_console_definitions_without_dispatching_jobs(): void
    {
        $before = $this->store()->table('jobs')->count();
        $response = $this->actingAs($this->fixtures['users']['operator'])->get('/current/schedule')
            ->assertOk()->assertSee('Deactivate subscriptions that have expired')->assertSee('Sundays · 21:30');
        $this->assertGreaterThan(25, $response->viewData('tasks')->total());
        $this->assertSame($before, $this->store()->table('jobs')->count());
    }

    public function test_real_training_job_writes_phases_and_captures_uninstrumented_source(): void
    {
        dispatch((new CreateTrainingPhasesJob($this->fixtures['plan'], [
            ['name' => 'Foundation', 'startWeek' => 1, 'endWeek' => 2],
            ['name' => 'Build', 'startWeek' => 3, 'endWeek' => 4],
        ]))->onQueue('training-plans'));
        $this->work('training-plans');
        $this->assertSame(2, DB::table('training_phases')->where('training_plan_id', $this->fixtures['plan'])->count());
        $job = $this->store()->table('jobs')->first();
        $this->assertSame('completed', $job->status);
        $this->assertSame(CreateTrainingPhasesJob::class, $job->job_class);
        $this->assertSame('ADAPT isolated lab', json_decode($job->metadata_json, true)['integration']);
        $this->assertSame($this->fixtures['plan'], json_decode($job->arguments_json, true)['trainingPlanId']);
        $this->assertSame('captured', $job->arguments_state);
        $this->assertSame(3, $job->max_tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame(0, $this->store()->table('steps')->count());
        $attempt = $this->store()->table('attempts')->first();
        $this->assertSame('app/Jobs/TrainingPlan/CreateTrainingPhasesJob.php', $attempt->source_file);
        $this->assertNotNull($attempt->source_hash);
        $this->actingAs($this->fixtures['users']['operator'])->get('/current/jobs/'.$job->id)->assertOk()->assertDontSee('No internal steps captured')->assertDontSee('Execution map')->assertDontSee('aria-label="Execution steps"', false)->assertDontSee('Open in Horizon')->assertSee('TrainingPhase::insertOrIgnore()');
    }

    public function test_real_training_error_maps_actual_application_file_and_filters(): void
    {
        $job = new CreateTrainingPhasesJob($this->fixtures['plan'], [['name' => 'Missing dates']]);
        $job->tries = 1;
        dispatch($job->onQueue('training-plans'));
        $this->work('training-plans');
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('failed', $record->status);
        $attempt = $this->store()->table('attempts')->first();
        $this->assertSame('ErrorException', $attempt->exception_class);
        $this->assertSame('app/Jobs/TrainingPlan/CreateTrainingPhasesJob.php', $attempt->exception_file);
        $this->assertGreaterThan(0, $attempt->exception_line);
        $this->assertNull($attempt->exception_message);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->actingAs($this->fixtures['users']['operator']);
        $this->get('/current/jobs/'.$record->id)->assertOk()->assertSee('Failure location')->assertSee($attempt->exception_file.':'.$attempt->exception_line);
        $this->get('/current/jobs/failed?'.http_build_query([
            'queue' => 'training-plans', 'job_class' => CreateTrainingPhasesJob::class,
            'job_id' => $record->external_id, 'exception_class' => 'ErrorException',
            'from' => gmdate('Y-m-d\TH:i:s', time() - 60), 'to' => gmdate('Y-m-d\TH:i:s', time() + 60),
        ]))->assertOk()->assertSee($record->external_id);
        $this->get('/current/monitoring?queue=notifications')->assertOk()->assertDontSee($record->external_id);
    }

    public function test_unique_push_dispatch_records_one_job_and_mock_instrumentation_is_nested(): void
    {
        $id = $this->fixtures['notifications']['success'];
        dispatch((new SendPushNotificationJob($id))->onQueue('notifications'));
        dispatch((new SendPushNotificationJob($id))->onQueue('notifications'));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->work('notifications');
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('completed', $record->status);
        $steps = $this->store()->table('steps')->orderBy('sequence')->get();
        $this->assertCount(2, $steps);
        $this->assertSame($steps[0]->id, $steps[1]->parent_id);
        $this->assertSame(['completed', 'completed'], $steps->pluck('status')->all());
        foreach (['metrics', 'counts', 'masters', 'supervisors', 'wait', 'workload'] as $source) {
            $this->assertTrue(app(HorizonAdapter::class)->dashboard()[$source]['available']);
        }
        $this->assertTrue(app(HorizonAdapter::class)->page('metrics', 'jobs')['available']);
    }

    public function test_push_exception_retries_keep_separate_attempts_and_redact_before_persistence(): void
    {
        dispatch((new SendPushNotificationJob($this->fixtures['notifications']['retry']))->onQueue('notifications'));
        $this->work('notifications');
        $this->assertSame('pending', $this->store()->table('jobs')->first()->status);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->work('notifications');
        $this->assertSame('completed', $this->store()->table('jobs')->first()->status);
        $attempts = $this->store()->table('attempts')->orderBy('started_at')->get();
        $this->assertSame(['released', 'completed'], $attempts->pluck('status')->all());
        $this->assertCount(2, $attempts->pluck('id')->unique());
        $this->assertStringNotContainsString('never-persist-this', json_encode($this->store()->table('attempts')->get()));
        $this->assertStringNotContainsString('never-persist-this', json_encode($this->store()->table('steps')->get()));
    }

    public function test_actual_garmin_releases_do_not_become_false_completions(): void
    {
        dispatch((new FetchGarminActivityDetail($this->fixtures['connection'], 999999, 'synthetic-activity'))->onQueue('default'));
        $this->work('default');
        $this->assertSame('pending', $this->store()->table('jobs')->first()->status);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->work('default');
        $this->assertSame('pending', $this->store()->table('jobs')->first()->status);
        Carbon::setTestNow(now()->addSeconds(301));
        $this->work('default');
        $this->assertSame('completed', $this->store()->table('jobs')->first()->status);
        $this->assertSame(['released', 'released', 'completed'], $this->store()->table('attempts')->orderBy('started_at')->pluck('status')->all());
        $this->assertSame(0, DB::table('workout_log_details')->count());
    }

    public function test_actual_horizon_master_reports_the_four_adapt_queue_groups_and_workers(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'horizon', '--environment=testing'], base_path(), timeout: 20);
        try {
            $process->start();
            $deadline = microtime(true) + 12;
            do {
                $snapshot = app(HorizonAdapter::class)->dashboard();
                $supervisors = $snapshot['supervisors']['data'] ?? [];
                if (count($supervisors) === 4 && array_sum(array_column($supervisors, 'processes')) >= 4) {
                    break;
                }
                usleep(100000);
            } while ($process->isRunning() && microtime(true) < $deadline);
            $this->assertSame('running', $snapshot['masters']['data']['status'], $process->getOutput().$process->getErrorOutput());
            $this->assertCount(4, $supervisors, $process->getOutput());
            $this->assertSame(4, array_sum(array_column($supervisors, 'processes')));
            $names = implode(',', array_column($supervisors, 'queues'));
            foreach (['training-plans', 'notifications', 'classifications', 'default', 'map-generation', 'route-imports', 'route-enrichment'] as $queue) {
                $this->assertStringContainsString($queue, $names);
            }
            $this->assertTrue($snapshot['workload']['available']);
            $this->assertNotEmpty($snapshot['workload']['data']);
            dispatch((new CreateTrainingPhasesJob($this->fixtures['plan'], [
                ['name' => 'Processed by Horizon', 'startWeek' => 1, 'endWeek' => 2],
            ]))->onQueue('training-plans'));
            $deadline = microtime(true) + 8;
            do {
                $record = $this->store()->table('jobs')->where('job_class', CreateTrainingPhasesJob::class)->first();
                if ($record?->status === 'completed') {
                    break;
                }
                usleep(100000);
            } while ($process->isRunning() && microtime(true) < $deadline);
            $this->assertNotNull($record);
            $this->assertSame('completed', $record->status, $process->getOutput().$process->getErrorOutput());
            $this->assertSame(1, DB::table('training_phases')->where('training_plan_id', $this->fixtures['plan'])->count());
            $response = $this->actingAs($this->fixtures['users']['operator'])->get('/current/queues')
                ->assertOk()->assertSee('ClassifyWorkoutLog')->assertSee('AnalyzeWorkoutCompletion')
                ->assertSee('ImportUserRouteChunk')->assertSee('EnrichRouteElevation')->assertDontSee('None observed');
            $this->assertCount(4, $response->viewData('supervisorTables'));
            $this->assertSame(4, array_sum(array_column($response->viewData('supervisorTables'), 'allocated')));

        } finally {
            if ($process->isRunning()) {
                $process->stop(8, SIGTERM);
            }
        }
    }
}
