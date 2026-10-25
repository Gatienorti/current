<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__).'/prototype/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$settings = json_decode(getenv('LENS_TEST_SETTINGS'), true, flags: JSON_THROW_ON_ERROR);
foreach ($settings as $key => $value) {
    config()->set($key, $value);
}
exit(Artisan::call('queue:work', [
    'connection' => 'redis', '--queue' => 'lens-demo', '--stop-when-empty' => true, '--max-jobs' => 1,
    '--sleep' => 0, '--tries' => 3, '--timeout' => 2,
]));
