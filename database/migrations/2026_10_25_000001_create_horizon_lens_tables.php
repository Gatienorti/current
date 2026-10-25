<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('horizon-lens.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('lens_jobs', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('external_id', 100)->index();
            $table->string('connection', 100);
            $table->string('queue', 100)->index();
            $table->string('job_class', 240)->index();
            $table->string('status', 30)->index();
            $table->string('coverage', 30)->default('observed_enqueue');
            $table->string('retry_of', 100)->nullable();
            $table->double('first_observed_at');
            $table->double('observed_at')->index();
            $table->double('expires_at')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('exception_class', 240)->nullable()->index();
            $table->index(['connection', 'external_id']);
            $table->index(['status', 'queue', 'observed_at']);
        });
        $schema->create('lens_tags', function (Blueprint $table) {
            $table->string('job_id', 26);
            $table->string('tag', 100)->index();
            $table->primary(['job_id', 'tag']);
        });
        $schema->create('lens_attempts', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('job_id', 26)->index();
            $table->unsignedInteger('reported_attempt');
            $table->string('worker_id', 60);
            $table->string('status', 30);
            $table->double('started_at');
            $table->double('finished_at')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('source_revision', 100)->nullable();
            $table->string('source_file', 240)->nullable();
            $table->text('source_operations')->nullable();
            $this->exceptionColumns($table);
        });
        $schema->create('lens_steps', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('attempt_id', 26)->index();
            $table->string('parent_id', 26)->nullable();
            $table->unsignedInteger('sequence');
            $table->string('label', 120);
            $table->string('status', 30);
            $table->double('started_at');
            $table->double('finished_at')->nullable();
            $this->exceptionColumns($table);
        });
        $schema->create('lens_meta', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value');
        });
    }

    private function exceptionColumns(Blueprint $table): void
    {
        $table->string('exception_class', 240)->nullable();
        $table->text('exception_message')->nullable();
        $table->string('exception_file', 240)->nullable();
        $table->unsignedInteger('exception_line')->nullable();
        $table->text('exception_trace')->nullable();
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['lens_steps', 'lens_attempts', 'lens_tags', 'lens_jobs', 'lens_meta'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
