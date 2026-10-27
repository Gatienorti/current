<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string { return config('current.connection'); }
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('lens_jobs', fn (Blueprint $table) => $table->double('cleared_at')->nullable());
        $schema->create('lens_job_actions', function (Blueprint $table) {
            $table->char('identity_hash', 64)->primary();
            $table->string('kind', 16);
            $table->char('job_id', 26)->nullable();
            $table->double('observed_at');
            $table->double('expires_at')->index();
        });
    }
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('lens_job_actions');
        $schema->table('lens_jobs', fn (Blueprint $table) => $table->dropColumn('cleared_at'));
    }
};
