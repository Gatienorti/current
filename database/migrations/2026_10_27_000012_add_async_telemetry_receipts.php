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
        $schema->create('lens_async_receipts', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->double('expires_at')->index();
        });
        $schema->create('lens_async_versions', function (Blueprint $table) {
            $table->string('entity', 60)->primary();
            $table->unsignedInteger('revision');
            $table->double('expires_at')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('lens_async_versions');
        $schema->dropIfExists('lens_async_receipts');
    }
};
