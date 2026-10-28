<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string { return config('current.connection'); }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('lens_native_queue_samples', function (Blueprint $table) {
            $table->string('metric_key', 64);
            $table->unsignedBigInteger('bucket');
            $table->unsignedBigInteger('ready')->nullable();
            $table->unsignedBigInteger('workers')->nullable();
            $table->double('wait_seconds')->nullable();
            $table->double('observed_at');
            $table->double('expires_at')->index();
            $table->primary(['metric_key', 'bucket']);
        });
    }

    public function down(): void { Schema::connection($this->getConnection())->dropIfExists('lens_native_queue_samples'); }
};
