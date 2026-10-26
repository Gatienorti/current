<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/bootstrap.php';

$app = bootAdaptLab(sys_get_temp_dir().'/current-reverb-server-'.bin2hex(random_bytes(6)));
$app->make(Kernel::class)->bootstrap();
config()->set('reverb.servers.reverb.scaling.enabled', false);
config()->set('reverb.apps', [
    'provider' => 'config',
    'apps' => [[
        'key' => 'current-lab-key', 'secret' => 'current-lab-secret', 'app_id' => 'current-lab',
        'allowed_origins' => ['localhost'], 'ping_interval' => 60, 'activity_timeout' => 30,
        'max_message_size' => 10000, 'max_connections' => 10,
    ]],
]);
Artisan::call('reverb:start', ['--host' => '127.0.0.1', '--port' => (int) $argv[1]], new ConsoleOutput);
