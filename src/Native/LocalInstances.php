<?php

namespace Current\Native;

use RuntimeException;

/** Discover private application-local records without validating a replacement plan. */
final class LocalInstances
{
    public function names(bool $all, mixed $selected = null): array
    {
        $directory = config('current.native.state_directory');
        if (! is_string($directory) || ! str_starts_with($directory, '/') || str_contains($directory, "\0") || is_link($directory)
            || (file_exists($directory) && ! is_dir($directory)) || (is_dir($directory) && (fileperms($directory) & 0077))) {
            throw new RuntimeException('Use the application private native state directory.');
        }
        if (! $all) {
            $name = $selected ?? config('current.native.instance');
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name)) { throw new RuntimeException('Invalid native instance name.'); }
            return [$name];
        }
        if ($selected !== null) { throw new RuntimeException('Choose one instance or --all.'); }
        $files = is_dir($directory) ? glob(rtrim($directory, '/').'/*.json') : [];
        if ($files === false || count($files) > 256) { throw new RuntimeException('Local supervisor discovery exceeds its bound.'); }
        return array_values(array_filter(array_map(fn ($file) => basename($file, '.json'), $files), fn ($name) => preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name)));
    }
}
