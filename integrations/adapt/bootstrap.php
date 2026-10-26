<?php

use Current\AdaptLab\SandboxServiceProvider;
use Current\CurrentServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

function bootAdaptLab(?string $state = null): Application
{
    $discovery = (bool) getenv('CURRENT_ADAPT_DISCOVERY');
    $source = getenv('ADAPT_PATH') ?: '/opt/adapt';
    $state ??= getenv('LENS_ADAPT_STATE') ?: sys_get_temp_dir().'/current-adapt';
    putenv('LENS_ADAPT_STATE='.$state);
    $_ENV['LENS_ADAPT_STATE'] = $_SERVER['LENS_ADAPT_STATE'] = $state;
    if (! is_file($source.'/bootstrap/app.php')) {
        throw new RuntimeException('Set ADAPT_PATH to the ADAPT application checkout.');
    }
    foreach (['bootstrap/cache', 'environment', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions', 'storage/logs', 'storage/app/private', 'data'] as $path) {
        if (! is_dir($state.'/'.$path)) {
            mkdir($state.'/'.$path, 0700, true);
        }
    }
    foreach (['app', 'routes', 'resources', 'database', 'public', 'vendor'] as $path) {
        if (! file_exists($state.'/'.$path)) {
            symlink($source.'/'.$path, $state.'/'.$path);
        }
    }
    foreach (['composer.json', 'bootstrap/providers.php'] as $file) {
        if (! is_file($state.'/'.$file)) {
            copy($source.'/'.$file, $state.'/'.$file);
        }
    }
    if (! is_file($state.'/artisan')) {
        file_put_contents($state.'/artisan', "<?php\nrequire ".var_export(__DIR__.'/artisan', true).";\n");
    }
    foreach (['app', 'lens'] as $database) {
        if (! is_file($state.'/data/'.$database.'.sqlite')) {
            touch($state.'/data/'.$database.'.sqlite');
        }
    }
    foreach (['PACKAGES' => 'packages.php', 'SERVICES' => 'services.php', 'CONFIG' => 'config.php', 'ROUTES' => 'routes.php', 'EVENTS' => 'events.php'] as $key => $file) {
        putenv('APP_'.$key.'_CACHE='.$state.'/bootstrap/cache/'.$file);
        $_ENV['APP_'.$key.'_CACHE'] = $_SERVER['APP_'.$key.'_CACHE'] = $state.'/bootstrap/cache/'.$file;
    }
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
    $loader = require $source.'/vendor/autoload.php';
    require_once __DIR__.'/SandboxServiceProvider.php';
    if (! $discovery) {
        $loader->addPsr4('Current\\', dirname(__DIR__, 2).'/src/');
    }
    $loader->addPsr4('Current\\AdaptTests\\', __DIR__.'/tests/');
    $app = require $source.'/bootstrap/app.php';
    $app->setBasePath($state);
    $app->useAppPath($source.'/app');
    if ($discovery) {
        if (! is_dir($state.'/config')) {
            mkdir($state.'/config', 0700, true);
            foreach (glob($source.'/config/*.php') as $file) {
                copy($file, $state.'/config/'.basename($file));
            }
        }
        $app->useConfigPath($state.'/config');
    } else {
        $app->useConfigPath($source.'/config');
    }
    $app->useBootstrapPath($state.'/bootstrap');
    $app->useLangPath($source.'/lang');
    $app->useStoragePath($state.'/storage');
    $app->useEnvironmentPath($state.'/environment');
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($source, $state) {
        $sqlite = fn ($name) => ['driver' => 'sqlite', 'database' => $state.'/data/'.$name.'.sqlite', 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 250, 'journal_mode' => 'WAL', 'transaction_mode' => 'IMMEDIATE'];
        $redis = ['host' => getenv('LENS_ADAPT_REDIS_HOST') ?: 'adapt-redis', 'port' => 6379, 'database' => 0];
        $prefix = 'lens_adapt_'.substr(hash('sha256', $state), 0, 16).':';
        $supervisors = $app['config']->get('horizon.defaults');
        foreach ($supervisors as &$options) {
            $options = array_merge($options, ['minProcesses' => 1, 'maxProcesses' => 1, 'balance' => false, 'maxTime' => 60]);
        }
        unset($options);
        $app['config']->set([
            'app.name' => 'ADAPT · isolated lab', 'app.env' => 'testing', 'app.debug' => false,
            'app.url' => 'http://127.0.0.1:8098', 'app.key' => 'base64:'.base64_encode(hash('sha256', 'lens-adapt-isolated-fixture', true)),
            'database.default' => 'app', 'database.connections' => ['app' => $sqlite('app'), 'sqlite' => $sqlite('app'), 'mysql' => $sqlite('app'), 'lens' => $sqlite('lens')],
            'database.redis' => ['client' => 'phpredis', 'options' => ['prefix' => $prefix], 'default' => $redis, 'cache' => $redis],
            'queue.default' => 'redis', 'queue.connections' => ['redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 1800, 'block_for' => null, 'after_commit' => false]],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => 'app', 'table' => 'failed_jobs'],
            'queue.batching.database' => 'app', 'cache.default' => 'redis', 'session.driver' => 'file',
            'session.files' => $state.'/storage/framework/sessions', 'session.cookie' => 'lens_adapt_lab', 'session.secure' => false,
            'logging.default' => 'single', 'logging.channels.single' => ['driver' => 'single', 'path' => $state.'/storage/logs/laravel.log', 'level' => 'warning'],
            'broadcasting.default' => 'log', 'mail.default' => 'array', 'telescope.enabled' => false,
            'pulse.enabled' => false, 'nightwatch.enabled' => false, 'sentry.dsn' => null,
            'filesystems.default' => 'local', 'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => $state.'/storage/app/private'], 'public' => ['driver' => 'local', 'root' => $state.'/storage/app/public']],
            'view.compiled' => $state.'/storage/framework/views', 'horizon.prefix' => $prefix.'horizon:',
            'horizon.environments.testing' => $supervisors,
            'current.path' => getenv('CURRENT_ADAPT_ROUTE') ?: 'current', 'current.connection' => 'lens', 'current.queue_connections' => ['redis'],
            'current.source_roots' => [$source.'/app'], 'current.tag_prefixes' => [],
            'current.diagnostic_path' => $state.'/storage/logs/lens-health.json',
        ]);
    });
    $app->afterBootstrapping(RegisterProviders::class, function ($app) use ($discovery) {
        if (! $discovery) {
            $app->register(CurrentServiceProvider::class);
        }
        $app->register(SandboxServiceProvider::class);
    });

    return $app;
}
