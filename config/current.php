<?php

use Current\Http\Authorize;

return [
    'domain' => env('CURRENT_DOMAIN'),
    // Native mode is opt-in; configure current-redis and run current:supervise separately.
    'execution_backend' => env('CURRENT_EXECUTION_BACKEND', 'horizon'),
    'native' => [
        'state_directory' => env('CURRENT_NATIVE_STATE_DIRECTORY'),
        'instance' => env('CURRENT_NATIVE_INSTANCE'),
        'controls' => false,
        'binary' => env('CURRENT_SUPERVISOR_BINARY', storage_path('current/current-supervisor')),
        'grace_seconds' => 90,
        'memory_limit' => 64, // MiB per launcher/supervisor, excluding PHP worker children; drain on excess.
        'worker_output' => 'quiet', // quiet, cli, or json (when Laravel supports it).
        'worker_limit' => 256, // Total live workers across this instance, including draining workers.
        'pools' => [], // Named pools: connection, queues, workers, min_workers, max_workers, timeout, memory, tries.
        'defaults' => [], // Named pool defaults, merged with pools or the selected environment.
        'environment' => env('CURRENT_NATIVE_ENVIRONMENT'), // Optional provisioning selection; application environment stays unchanged.
        'environments' => [], // Optional environment patterns => named pool overrides; put '*' last.
        'registry' => ['enabled' => true, 'redis_connection' => 'default', 'namespace' => 'current'],
        'waits' => [], // Opt-in connection:queue => estimated clearance threshold in seconds.
        'wait_cooldown' => 300, // Shared notification-event cooldown, 60–86400 seconds.
        'notifications' => ['enabled' => false, 'mail' => null, 'slack' => null, 'slack_channel' => null, 'sms' => null],
        'watch' => ['app', 'bootstrap', 'config', 'database', 'routes', 'composer.json', 'composer.lock', '.env'],
        'tag_retention_days' => [], // Exact approved tag => days, capped by max_retention_days; new job histories only.
        'metrics' => ['enabled' => true, 'retention_hours' => 24],
        'monitoring' => ['redis_connection' => 'default', 'namespace' => 'current', 'retention_days' => 7, 'max_tags' => 128],
        'silenced' => [], // Explicit job class names.
        'silenced_tags' => [], // Explicit tag values.
    ],
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
