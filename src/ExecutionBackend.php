<?php

namespace Current;

/** Explicit UI capabilities; selecting native does not start or configure workers. */
final class ExecutionBackend
{
    public static function native(): bool
    {
        return config('current.execution_backend', 'horizon') === 'native';
    }

    public static function supports(string $capability): bool
    {
        return match (config('current.execution_backend', 'horizon')) {
            'horizon' => true,
            'native' => in_array($capability, ['queues', 'metrics', 'batches', 'silenced', 'retry', 'native', 'index', 'dashboard', 'monitoring', 'recent', 'pending', 'processing', 'completed', 'failed', 'show', 'schedule', 'webhooks', 'broadcasts', 'css', 'theme', 'filters', 'steps', 'map', 'refresh'], true),
            default => false,
        };
    }
}
