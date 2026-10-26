<?php

return [
    'default' => 'app',
    'connections' => [
        'app' => ['driver' => 'sqlite', 'database' => storage_path('data/app.sqlite'), 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 250, 'journal_mode' => 'WAL', 'transaction_mode' => 'IMMEDIATE'],
        'lens' => ['driver' => 'sqlite', 'database' => getenv('LENS_DATABASE') ?: storage_path('data/lens.sqlite'), 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 250, 'journal_mode' => 'WAL', 'transaction_mode' => 'IMMEDIATE'],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => ['client' => 'phpredis', 'options' => ['prefix' => 'current_lab:'], 'default' => ['host' => getenv('LENS_REDIS_HOST') ?: 'lens-redis', 'port' => 6379, 'database' => 0]],
];
