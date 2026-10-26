<?php

// Upgrade bridge for an existing Composer installation's cached discovery entry.
// New installations discover Current\CurrentServiceProvider directly.
foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->addPsr4('Current\\', __DIR__);
}

class_alias(\Current\CurrentServiceProvider::class, 'HorizonLens\\LensServiceProvider');

require_once __DIR__.'/LegacyAliases.php';
