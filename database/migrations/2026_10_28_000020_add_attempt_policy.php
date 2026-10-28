<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('current.connection'))->table('lens_attempts', function (Blueprint $table) {
            $table->text('policy_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('current.connection'))->table('lens_attempts', fn (Blueprint $table) => $table->dropColumn('policy_json'));
    }
};
