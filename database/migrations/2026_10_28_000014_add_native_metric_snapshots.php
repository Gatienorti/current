<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string { return config('current.connection'); }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('lens_native_metrics', function (Blueprint $table) {
            $table->string('metric_key', 64);
            $table->unsignedBigInteger('bucket');
            $table->string('kind', 10);
            $table->string('label', 240);
            $table->unsignedBigInteger('completed');
            $table->unsignedBigInteger('failed');
            $table->unsignedBigInteger('duration_samples');
            $table->double('runtime_ms')->nullable();
            $table->double('observed_at');
            $table->double('expires_at')->index();
            $table->primary(['metric_key', 'bucket']);
        });
        $schema->table('lens_jobs', fn (Blueprint $table) => $table->index('batch_id', 'lens_jobs_batch_id_index'));
        $schema->table('lens_attempts', fn (Blueprint $table) => $table->index('finished_at', 'lens_attempts_finished_at_index'));
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('lens_attempts', fn (Blueprint $table) => $table->dropIndex('lens_attempts_finished_at_index'));
        $schema->table('lens_jobs', fn (Blueprint $table) => $table->dropIndex('lens_jobs_batch_id_index'));
        $schema->dropIfExists('lens_native_metrics');
    }
};
