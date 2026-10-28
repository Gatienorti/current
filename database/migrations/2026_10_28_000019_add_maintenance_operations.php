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
        Schema::connection($this->getConnection())->create('lens_maintenance_operations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('kind', 20);
            $table->text('value');
            $table->double('expires_at')->index();
            $table->char('owner_token', 26)->nullable();
            $table->double('lease_until')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('lens_maintenance_operations');
    }
};
