<?php
// Additive upgrades for old isolated previews created without a migration ledger.
// Real installations use current:install and its dedicated repository instead.
return static function (): void {
    $schema = Illuminate\Support\Facades\Schema::connection(config('current.connection'));
    $migrations = dirname(__DIR__).'/database/migrations/';
    foreach ([
        '2026_10_27_000012_add_async_telemetry_receipts.php' => ['lens_async_receipts', null],
        '2026_10_28_000013_add_dispatch_identity.php' => ['lens_jobs', 'dispatch_id'],
        '2026_10_28_000014_add_native_metric_snapshots.php' => ['lens_native_metrics', null],
        '2026_10_28_000015_add_async_identity_aliases.php' => ['lens_async_aliases', null],
    ] as $file => [$table, $column]) {
        if ($column ? ! $schema->hasColumn($table, $column) : ! $schema->hasTable($table)) { (require $migrations.$file)->up(); }
    }
    $column = $schema->hasColumn('lens_jobs', 'cleared_at'); $actions = $schema->hasTable('lens_job_actions');
    if ($column !== $actions) { throw new RuntimeException('Preview maintenance migration is partial; restore its backup before retrying.'); }
    if (! $column) { (require $migrations.'2026_10_28_000016_add_native_maintenance.php')->up(); }
};
