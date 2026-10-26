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
        $schema->create('lens_broadcasts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('connection', 80)->index();
            $table->string('event', 160)->index();
            $table->text('channels_json');
            $table->string('outcome', 24)->default('unknown')->index();
            $table->double('started_at')->index();
            $table->double('finished_at')->nullable();
            $table->double('duration_ms')->nullable();
            $table->string('exception_class', 240)->nullable();
            $table->char('job_id', 26)->nullable()->index();
            $table->char('attempt_id', 26)->nullable();
            $table->double('expires_at')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('lens_broadcasts');
    }
};
