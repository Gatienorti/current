<?php

use HorizonLens\Http\Authorize;

return [
    'enabled' => env('CURRENT_ENABLED', env('HORIZON_LENS_ENABLED', true)),
    'allow_retries' => env('CURRENT_ALLOW_RETRIES', true),
    'path' => env('CURRENT_PATH', 'current'),
    'middleware' => ['web', Authorize::class],
    // Use a dedicated connection, with driver-level timeouts set by the host.
    'connection' => env('CURRENT_CONNECTION', env('HORIZON_LENS_CONNECTION', 'current')),
    'queue_connections' => ['redis'],
    'retention_days' => 7,
    'max_retention_days' => 30,
    'tag_prefixes' => [],
    'message_sanitizer' => null,
    // Automatic data-only decoding; encrypted/closure commands are never opened.
    'capture_payload' => env('CURRENT_CAPTURE_PAYLOAD', true),
    // Optional fn (object $job): array with explicit 'arguments' / 'metadata' values.
    // Jobs may instead expose currentArguments() and/or currentMetadata(). Legacy lens* methods remain supported.
    'job_context' => null,
    'context_max_bytes' => 16384,
    'context_max_depth' => 5,
    'context_max_items' => 100,
    'context_max_string' => 2048,
    'source_roots' => [app_path()],
    'source_revision' => env('HORIZON_LENS_REVISION'),
    'max_source_bytes' => 262144,
    'max_source_operations' => 100,
    // Per-worker LRU cache; 0 disables it. Also bounded to 2 MiB of outline JSON.
    'source_cache_entries' => 128,
    'max_steps' => 100,
    'max_frames' => 20,
    // Display a missing-finish notice after this age; never infer worker loss or change outcome.
    'stale_after_seconds' => 300,
    'diagnostic_path' => storage_path('logs/current-health.json'),
];
