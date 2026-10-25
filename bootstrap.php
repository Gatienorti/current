<?php

// Prefer a standalone install; the local lab can explicitly reuse a host vendor tree.
$autoload = getenv('LENS_VENDOR_AUTOLOAD') ?: __DIR__.'/vendor/autoload.php';
if (! is_file($autoload)) {
    $autoload = dirname(__DIR__).'/vehify/vehify-web/vendor/autoload.php';
}
if (! is_file($autoload)) {
    throw new RuntimeException('Run composer install or set LENS_VENDOR_AUTOLOAD to a supported Laravel/Horizon vendor/autoload.php.');
}
$loader = require $autoload;
$loader->addPsr4('HorizonLens\\Tests\\', __DIR__.'/tests/');
$loader->addPsr4('HorizonLens\\Prototype\\', __DIR__.'/prototype/');
$loader->addPsr4('HorizonLens\\', __DIR__.'/src/');

$loader->addPsr4('Current\\', __DIR__.'/src/Current/');

return $loader;
