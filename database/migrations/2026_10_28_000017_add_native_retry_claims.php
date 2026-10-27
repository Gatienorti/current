<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string { return config('current.connection'); }
    public function up(): void
    {
        Schema::connection($this->getConnection())->create('lens_native_retry_claims', function (Blueprint $table) {
            $table->char('identity_hash', 64)->primary();
            $table->text('value');
            $table->double('expires_at')->index();
        });
        // Preserve pre-upgrade unknown outcomes before ordinary history pruning
        // can remove the older per-history meta keys. No executable data is read.
        $db = \Illuminate\Support\Facades\DB::connection($this->getConnection());
        $expires = microtime(true) + max(1, min(366, (int) config('current.max_retention_days', 30))) * 86400 + 86400;
        $db->table('lens_jobs')->whereNotNull('dispatch_id')->orderBy('id')->chunkById(500, function ($jobs) use ($db, $expires) {
            $states = $db->table('lens_meta')->whereIn('key', $jobs->map(fn ($job) => 'retry:'.$job->id)->all())->pluck('value', 'key');
            foreach ($jobs as $job) {
                $state = $states['retry:'.$job->id] ?? null;
                if (! is_string($state) || ! \Current\Support\DispatchIdentity::fromPayload(['_current_dispatch_id' => $job->dispatch_id])) { continue; }
                $db->table('lens_native_retry_claims')->insertOrIgnore([
                    'identity_hash' => \Current\Support\DispatchIdentity::fingerprint($job->external_id, $job->connection, $job->dispatch_id),
                    'value' => $state, 'expires_at' => $expires,
                ]);
            }
        });
    }
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('lens_native_retry_claims');
    }
};
