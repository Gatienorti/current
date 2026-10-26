<?php

namespace HorizonLens\Support;

use Throwable;

final class Sanitizer
{
    public function tags(array $tags): array
    {
        return array_values(array_slice(array_unique(array_filter($tags, function ($tag) {
            if (! is_string($tag) || strlen($tag) > 100) {
                return false;
            }
            foreach (config('horizon-lens.tag_prefixes', []) as $prefix) {
                if (str_starts_with($tag, $prefix)) {
                    return true;
                }
            }

            return false;
        })), 0, 20));
    }

    public function location(string $file): ?string
    {
        $path = realpath($file);
        foreach (config('horizon-lens.source_roots', []) as $root) {
            $root = realpath($root);
            if ($path && $root && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return basename($root).'/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            }
        }

        return null;
    }

    public function exception(Throwable $error, ?callable $messageSanitizer = null, bool $useDefault = true): array
    {
        $message = null;
        if ($sanitizer = ($useDefault ? config('horizon-lens.message_sanitizer') : $messageSanitizer)) {
            $message = mb_substr((string) $sanitizer($error->getMessage()), 0, 1000);
        }
        $frames = [];
        // No arguments, object instances, exception context, or serialized command.
        foreach (array_slice($error->getTrace(), 0, config('horizon-lens.max_frames', 20)) as $frame) {
            $frames[] = [
                'file' => isset($frame['file']) ? $this->location($frame['file']) : null,
                'line' => $frame['line'] ?? null,
                'call' => mb_substr(($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''), 0, 240),
            ];
        }

        return [
            'exception_class' => mb_substr($error::class, 0, 240),
            'exception_message' => $message,
            'exception_file' => $this->location($error->getFile()),
            'exception_line' => $error->getLine(),
            'exception_trace' => json_encode($frames, JSON_THROW_ON_ERROR),
        ];
    }
}
