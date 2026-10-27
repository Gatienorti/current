<?php

use App\Jobs\FetchGarminActivityDetail;
use App\Jobs\SendPushNotificationJob;
use App\Jobs\TrainingPlan\CreateTrainingPhasesJob;
use Current\AdaptLab\Fixtures;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/bootstrap.php';
require __DIR__.'/Fixtures.php';
$app = bootAdaptLab();
$app->make(Kernel::class)->bootstrap();
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
if (Artisan::call('current:install', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$fixtures = Fixtures::seed();
$work = function (string $queue) {
    $exit = Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--sleep' => 0]);
    if ($exit !== 0) {
        throw new RuntimeException('Lab worker failed.');
    }
};
dispatch((new CreateTrainingPhasesJob($fixtures['plan'], [
    ['name' => 'Foundation', 'startWeek' => 1, 'endWeek' => 2],
    ['name' => 'Build', 'startWeek' => 3, 'endWeek' => 4],
]))->onQueue('training-plans'));
$work('training-plans');
$invalid = new CreateTrainingPhasesJob($fixtures['plan'], [['name' => 'Missing dates']]);
$invalid->tries = 1;
dispatch($invalid->onQueue('training-plans'));
$work('training-plans');
foreach (['success', 'retry', 'failure'] as $scenario) {
    $job = (new SendPushNotificationJob($fixtures['notifications'][$scenario]))->onQueue('notifications');
    if ($scenario === 'failure') {
        $job->tries = 1;
    }
    dispatch($job);
    $work('notifications');
    if ($scenario === 'retry') {
        Carbon::setTestNow(now()->addSeconds(61));
        $work('notifications');
        Carbon::setTestNow();
    }
}
dispatch((new FetchGarminActivityDetail($fixtures['connection'], 999999, 'synthetic-activity'))->onQueue('default'));
foreach ([0, 61, 362] as $offset) {
    Carbon::setTestNow(Carbon::now()->addSeconds($offset));
    $work('default');
    Carbon::setTestNow();
}
echo 'ADAPT lab ready: http://127.0.0.1:8098/__lens-lab/login'.PHP_EOL;
echo 'Synthetic operator: operator@lens.invalid / lens-local-only'.PHP_EOL;
