<?php

require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/Fixtures.php';
require (getenv('ADAPT_PATH') ?: '/opt/adapt').'/vendor/autoload.php';
// PHPUnit loads classes before createApplication registers the source paths.
spl_autoload_register(function ($class) {
    $prefix = 'HorizonLens\\AdaptTests\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/'.substr($class, strlen($prefix)).'.php';
    }
});
