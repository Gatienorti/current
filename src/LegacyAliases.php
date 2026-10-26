<?php

// Optimized Composer maps from earlier installations still point this public API
// at its former nested path. Keep the installed checkout usable during upgrade.
foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
    $loader->addClassMap(['Current\\Current' => __DIR__.'/Current.php']);
}

// Old published config or queued prototype payloads may still reference this prefix.
spl_autoload_register(static function (string $class): void {
    $prefix = 'HorizonLens\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $suffix = substr($class, strlen($prefix));
    $replacement = 'Current\\'.($suffix === 'Lens' ? 'Current' : $suffix);
    if (class_exists($replacement) || interface_exists($replacement)) {
        class_alias($replacement, $class);
    }
}, true, true);
