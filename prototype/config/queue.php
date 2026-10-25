<?php

return [
    'default' => 'redis',
    'connections' => ['redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'lens-demo', 'retry_after' => 5, 'block_for' => null, 'after_commit' => false]],
    'failed' => ['driver' => 'database-uuids', 'database' => 'app', 'table' => 'failed_jobs'],
];
