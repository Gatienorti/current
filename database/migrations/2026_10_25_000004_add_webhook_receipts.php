<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('current.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('lens_webhook_receipts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('provider', 80)->index();
            $table->string('route', 240);
            $table->string('method', 12);
            $table->double('received_at')->index();
            $table->double('finished_at')->nullable();
            $table->double('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable()->index();
            $table->string('signature', 16)->default('unknown');
            $table->string('exception_class', 240)->nullable();
            $table->double('expires_at')->index();
        });
        $schema->create('lens_webhook_jobs', function (Blueprint $table) {
            $table->char('receipt_id', 26);
            $table->char('job_id', 26)->index();
            $table->primary(['receipt_id', 'job_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('lens_webhook_jobs');
        $schema->dropIfExists('lens_webhook_receipts');
    }
};
