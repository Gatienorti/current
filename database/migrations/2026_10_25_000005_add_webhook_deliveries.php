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
        $schema->create('lens_webhook_deliveries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('destination', 80)->index();
            $table->string('endpoint', 500);
            $table->string('method', 12);
            $table->double('started_at')->index();
            $table->double('finished_at')->nullable();
            $table->double('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable()->index();
            $table->string('exception_class', 240)->nullable();
            $table->char('job_id', 26)->nullable()->index();
            $table->char('attempt_id', 26)->nullable();
            $table->double('expires_at')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('lens_webhook_deliveries');
    }
};
