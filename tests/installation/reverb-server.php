<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\ConsoleOutput;

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('reverb.servers.reverb.scaling.enabled', false);
config()->set('reverb.apps', [
    'provider' => 'config',
    'apps' => [[
        'key' => 'current-install-key', 'secret' => 'current-install-secret', 'app_id' => 'current-install',
        'allowed_origins' => ['localhost'], 'ping_interval' => 60, 'activity_timeout' => 30,
        'max_message_size' => 10000, 'max_connections' => 10,
    ]],
]);
Artisan::call('reverb:start', ['--host' => '127.0.0.1', '--port' => (int) $argv[2]], new ConsoleOutput);
