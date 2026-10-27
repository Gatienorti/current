<?php

use Current\Prototype\Jobs\PaymentJob;
use Current\Recorder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Events\JobPushed;

require dirname(__DIR__, 2).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/prototype/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
config()->set($fixture['settings']);
app('db')->purge('lens');
app('redis')->purge('default');
app('redis')->purge('horizon');
$role = $argv[2];
$barrier = $fixture['barrier'];
$id = app('db')->connection('lens')->selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($barrier.'.'.$role.'.started', (string) $id);
$seen = false;
app('db')->listen(function ($query) use (&$seen, $role, $barrier, $fixture) {
    $matches = ($fixture['mode'] ?? '') === 'attempt-inserts'
        ? str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'lens_attempts')
        : str_starts_with($query->sql, 'select') && str_contains($query->sql, 'lens_jobs') && str_contains($query->sql, 'external_id');
    if ($seen || ! $matches) {
        return;
    }
    $seen = true;
    file_put_contents($barrier.'.'.$role.'.read', '1');
    if ($fixture['first'] === 'both' || $role === $fixture['first']) {
        $deadline = microtime(true) + 10;
        while (! is_file($barrier.'.release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrent recorder barrier timed out.');
            }
            usleep(5000);
        }
    }
});
if ($role === 'producer') {
    $payload = $fixture['payload'];
    app(Recorder::class)->pushed((new JobPushed($payload))->connection('redis')->queue('lens-demo'));
    app(Recorder::class)->queued(new JobQueued('redis', 'lens-demo', json_decode($payload, true)['uuid'], new PaymentJob('success'), $payload, null));
} else {
    exit(Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'lens-demo', '--once' => true, '--sleep' => 0, '--tries' => 3, '--timeout' => 15]));
}
