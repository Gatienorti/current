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
        $schema->table('lens_webhook_receipts', function (Blueprint $table) {
            $table->char('receipt_key', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('lens_webhook_receipts', function (Blueprint $table) {
            $table->dropIndex(['receipt_key']);
            $table->dropColumn('receipt_key');
        });
    }
};
