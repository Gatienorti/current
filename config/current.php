<?php

use Current\Http\Authorize;

return [
    'enabled' => env('CURRENT_ENABLED', true),
    'telemetry' => [
        'driver' => env('CURRENT_TELEMETRY_DRIVER', 'sync'), // sync or redis (opt-in SQLite collector).
        'redis_connection' => env('CURRENT_TELEMETRY_REDIS_CONNECTION', 'default'),
        'namespace' => env('CURRENT_TELEMETRY_NAMESPACE', 'current'),
        'binary' => env('CURRENT_COLLECTOR_BINARY', storage_path('current/current-collector')),
        'max_pending' => 2000,
        'max_event_bytes' => 131072,
        'max_age_seconds' => 3600,
        'lag_warning_seconds' => 10,
    ],
    'broadcasts' => [
        'visible' => null, // null: detect Reverb or the selected Pusher driver; true/false: explicit navigation override.
        'enabled' => env('CURRENT_BROADCASTS_ENABLED', false),
        'events' => [], // Exact approved broadcast names.
        'channels' => [], // Approved patterns only are persisted, e.g. private-chat.*.
        'context' => null, // Optional callable(event, approved channels, payload): approved payload/metadata exports.
        'message_sanitizer' => null, // Broadcast-only approved error message exporter.
        'capture_channels' => false, // Explicit opt-in to exact approved channel names.
        'slow_ms' => 1000,
        'authorization' => [
            'enabled' => false,
            'routes' => [], // Exact approved route names, if named.
            'paths' => [], // Exact route templates, e.g. broadcasting/auth (no leading slash).
        ],
    ],
    'webhooks' => [
        // Explicit application-provided exports only; never automatically read HTTP bodies.
        'capture_context' => env('CURRENT_WEBHOOK_CONTEXT', false),
        // Optional callable(PSR response, destination label): approved response data.
        'response_context' => null,
        'enabled' => env('CURRENT_WEBHOOKS_ENABLED', false),
        'outgoing' => [
            'enabled' => env('CURRENT_OUTGOING_WEBHOOKS_ENABLED', false),
            // Exact scheme, host, port and path. Use safe labels and paths; queries are never stored.
            'destinations' => [],
        ],
    ],
    'allow_retries' => env('CURRENT_ALLOW_RETRIES', true),
    'path' => env('CURRENT_PATH', 'current'),
    'middleware' => ['web', Authorize::class],
    // Use a dedicated connection, with driver-level timeouts set by the host.
    'connection' => env('CURRENT_CONNECTION', 'current'),
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
    'source_revision' => env('CURRENT_REVISION'),
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
