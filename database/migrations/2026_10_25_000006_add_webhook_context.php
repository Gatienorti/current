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
        foreach (['lens_webhook_receipts', 'lens_webhook_deliveries'] as $name) {
            $schema->table($name, function (Blueprint $table) {
                $table->string('event_id', 160)->nullable()->index();
                $table->string('event_type', 160)->nullable()->index();
                foreach (['payload', 'metadata', 'response'] as $field) {
                    $table->text($field.'_json')->nullable();
                    $table->string($field.'_state', 24)->default('not_captured');
                }
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['lens_webhook_receipts', 'lens_webhook_deliveries'] as $name) {
            $schema->table($name, function (Blueprint $table) {
                $table->dropIndex(['event_id']);
                $table->dropIndex(['event_type']);
                $table->dropColumn(['event_id', 'event_type', 'payload_json', 'payload_state', 'metadata_json', 'metadata_state', 'response_json', 'response_state']);
            });
        }
    }
};
