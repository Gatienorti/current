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
        Schema::connection($this->getConnection())->table('lens_jobs', function (Blueprint $table) {
            $table->boolean('silenced')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('lens_jobs', function (Blueprint $table) {
            $table->dropColumn('silenced');
        });
    }
};
