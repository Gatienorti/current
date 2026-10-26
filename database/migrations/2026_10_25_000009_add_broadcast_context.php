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
        $schema->table('lens_broadcasts', function (Blueprint $table) {
            $table->text('channel_types_json')->nullable();
            foreach (['payload', 'metadata', 'exact_channels'] as $field) {
                $table->text($field.'_json')->nullable();
                $table->string($field.'_state', 24)->default('not_captured');
            }
            $table->text('exception_message')->nullable();
            $table->string('exception_file', 240)->nullable();
            $table->unsignedInteger('exception_line')->nullable();
            $table->text('exception_trace')->nullable();
            $table->string('error_state', 24)->default('not_captured');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('lens_broadcasts', function (Blueprint $table) {
            $table->dropColumn(['channel_types_json', 'payload_json', 'payload_state', 'metadata_json', 'metadata_state', 'exact_channels_json', 'exact_channels_state', 'exception_message', 'exception_file', 'exception_line', 'exception_trace', 'error_state']);
        });
    }
};
