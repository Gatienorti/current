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
        $schema->table('lens_webhook_deliveries', function (Blueprint $table) {
            $table->char('delivery_key', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('lens_webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex(['delivery_key']);
            $table->dropColumn('delivery_key');
        });
    }
};
