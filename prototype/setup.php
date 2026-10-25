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
echo "Isolated lab storage ready.\n";
