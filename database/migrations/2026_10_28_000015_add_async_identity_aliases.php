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
        Schema::connection($this->getConnection())->create('lens_async_aliases', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('canonical_id', 26)->index();
            $table->char('identity_hash', 64);
            $table->double('first_observed_at');
            $table->double('job_expires_at');
            // Keep a tombstone beyond both producer and canonical retention.
            $table->double('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('lens_async_aliases');
    }
};
