<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/bootstrap.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
foreach (['app', 'lens'] as $connection) {
    $path = config("database.connections.{$connection}.database");
    if (! file_exists($path)) {
        touch($path);
    }
}
$schema = Schema::connection('app');
if (! $schema->hasTable('failed_jobs')) {
    $schema->create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}
if (! Schema::connection('lens')->hasTable('lens_jobs')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000001_create_horizon_lens_tables.php')->up();
}
if (! Schema::connection('lens')->hasColumn('lens_jobs', 'silenced')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000002_add_silenced_classification.php')->up();
}
if (! Schema::connection('lens')->hasColumn('lens_jobs', 'arguments_json')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000003_add_job_details.php')->up();
}
if (! Schema::connection('lens')->hasTable('lens_webhook_receipts')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000004_add_webhook_receipts.php')->up();
}
echo "Isolated lab storage ready.\n";

if (! Schema::connection('lens')->hasTable('lens_webhook_deliveries')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000005_add_webhook_deliveries.php')->up();
}

if (! Schema::connection('lens')->hasColumn('lens_webhook_receipts', 'event_id')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000006_add_webhook_context.php')->up();
}

if (! Schema::connection('lens')->hasColumn('lens_webhook_deliveries', 'delivery_key')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000007_add_delivery_grouping.php')->up();
}

if (! Schema::connection('lens')->hasTable('lens_broadcasts')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000008_add_broadcasts.php')->up();
}

if (! Schema::connection('lens')->hasColumn('lens_broadcasts', 'payload_json')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000009_add_broadcast_context.php')->up();
}

if (! Schema::connection(config('horizon-lens.connection'))->hasColumn('lens_webhook_receipts', 'receipt_key')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000010_add_receipt_grouping.php')->up();
}

if (! Schema::connection(config('horizon-lens.connection'))->hasTable('lens_broadcast_auth_failures')) {
    (require dirname(__DIR__).'/database/migrations/2026_10_25_000011_add_broadcast_authorization_failures.php')->up();
}
