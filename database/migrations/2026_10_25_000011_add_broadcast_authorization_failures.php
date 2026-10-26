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
        Schema::connection($this->getConnection())->create('lens_broadcast_auth_failures', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('route_name', 160)->index();
            $table->string('method', 12);
            $table->unsignedSmallInteger('http_status')->index();
            $table->string('channel_type', 24);
            $table->text('channels_json');
            $table->text('exact_channels_json')->nullable();
            $table->string('exact_channels_state', 24)->default('not_captured');
            $table->double('observed_at')->index();
            $table->double('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('lens_broadcast_auth_failures');
    }
};
