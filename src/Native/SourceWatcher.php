<?php

namespace Current\Native;

use RuntimeException;

/** Bounded development-only polling. File contents and hashes never leave memory. */
final class SourceWatcher
{
    private array $paths;

    public function __construct(private string $base, array $paths)
    {
        $base = realpath($base);
        if ($base === false || ! is_dir($base) || ! $paths || count($paths) > 64 || ! array_is_list($paths)) {
            throw new RuntimeException('Configure one to 64 native.watch relative files or directories.');
        }
        $this->base = rtrim($base, '/');
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, "\0")
                || preg_match('~(^|/)\.\.?(?:/|$)|[?*\[\]\\\\]~', $path)) {
                throw new RuntimeException('Native watch paths must be relative files or directories without wildcards or traversal.');
            }
        }
        $this->paths = array_values(array_unique(array_map(fn ($path) => preg_replace('~/+~', '/', rtrim($path, '/')), $paths)));
    }

    public function snapshot(): string
    {
        $entries = []; $bytes = 0; $visited = 0;
        foreach ($this->paths as $path) { $this->scan($this->base.'/'.$path, $entries, $bytes, $visited); }
        ksort($entries);

        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    private function scan(string $path, array &$entries, int &$bytes, int &$visited): void
    {
        // Laravel can rewrite generated manifests while a new launcher boots.
        // Watching bootstrap source must not turn those writes into restart loops.
        // An explicitly selected cache path still opts into watching that path.
        if ($path === $this->base.'/bootstrap/cache' && ! in_array('bootstrap/cache', $this->paths, true)) { return; }
        if (++$visited > 20000) { throw new RuntimeException('Native watch scan exceeds 20,000 entries. Narrow native.watch.'); }
        clearstatcache(true, $path);
        // Missing configured files are retained as a marker so their later creation is detected.
        if (is_link($path)) { throw new RuntimeException('Native watch paths must not contain symlinks.'); }
        if (! file_exists($path)) { $entries[$path] = null; return; }
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real, $this->base.'/')) { throw new RuntimeException('Native watch path escapes the application.'); }
        if (is_dir($path)) {
            $children = @scandir($path);
            if ($children === false) { throw new RuntimeException('Native watch directory is unreadable.'); }
            $entries[$path] = 'directory';
            foreach ($children as $name) {
                if ($name !== '.' && $name !== '..') { $this->scan($path.'/'.$name, $entries, $bytes, $visited); }
            }
            return;
        }
        if (! is_file($path) || ! is_readable($path)) { throw new RuntimeException('Native watch requires readable regular files.'); }
        $file = @fopen($path, 'rb');
        if (! $file) { throw new RuntimeException('Native watch file became unreadable during scanning.'); }
        try {
            $stat = fstat($file);
            if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > 8388608) {
                throw new RuntimeException('Native watch scan exceeds 8 MiB per file or 64 MiB total. Narrow native.watch.');
            }
            // Bound the actual read too: a concurrently growing file must not make
            // hashing unbounded. Hashes detect same-size edits at coarse mtimes.
            $contents = stream_get_contents($file, 8388609);
            if ($contents === false || strlen($contents) > 8388608 || ($bytes += strlen($contents)) > 67108864) {
                throw new RuntimeException('Native watch scan exceeds 8 MiB per file or 64 MiB total. Narrow native.watch.');
            }
            $entries[$path] = hash('sha256', $contents);
        } finally { fclose($file); }
    }
}
