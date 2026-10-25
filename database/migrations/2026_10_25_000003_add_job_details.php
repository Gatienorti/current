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
        Schema::connection($this->getConnection())->table('lens_jobs', function (Blueprint $table) {
            $table->text('arguments_json')->nullable();
            $table->text('metadata_json')->nullable();
            $table->string('arguments_state', 30)->nullable();
            $table->string('metadata_state', 30)->nullable();
            $table->double('context_captured_at')->nullable();
            $table->string('display_name', 240)->nullable();
            $table->unsignedInteger('max_tries')->nullable();
            $table->unsignedInteger('timeout')->nullable();
            $table->string('backoff', 240)->nullable();
            $table->double('retry_until')->nullable();
            $table->double('payload_created_at')->nullable();
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->string('batch_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('lens_jobs', fn (Blueprint $table) => $table->dropColumn([
            'arguments_json', 'metadata_json', 'arguments_state', 'metadata_state', 'context_captured_at',
            'display_name', 'max_tries', 'timeout', 'backoff', 'retry_until', 'payload_created_at', 'delay_seconds', 'batch_id',
        ]));
    }
};
