<?php

namespace Current\Native;

use Current\Store;
use Current\Telemetry\Health;
use Current\Telemetry\Transport;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Operator-side read checks. Never dispatches, installs, starts or controls a process. */
final class Readiness
{
    public function inspect(): array
    {
        $checks = []; $pools = [];
        $add = static function (string $id, string $status, string $message) use (&$checks): void { $checks[] = compact('id', 'status', 'message'); };
        $add('platform', PHP_OS_FAMILY === 'Linux' && extension_loaded('pcntl') && extension_loaded('posix') ? 'pass' : 'error', 'Native workers require Linux, pcntl and posix in the actual worker runtime.');
        $add('backend', config('current.execution_backend') === 'native' ? 'pass' : 'error', 'Select CURRENT_EXECUTION_BACKEND=native only as part of the reviewed cutover.');
        $add('capture', config('current.enabled') === true ? 'pass' : 'error', 'Current job observation must be enabled for investigation.');
        try {
            $pools = app(Plan::class)->pools();
            app(Plan::class)->runtime();
            $add('configuration', 'pass', 'Native pool, worker budget, timeout and retry_after settings validate.');
        } catch (Throwable) { $pools = []; $add('configuration', 'error', 'Native configuration is invalid. Run current:supervise --check after selecting native mode; review pools, current-redis drivers, instance, state path and timeout limits.'); }
        $redisNames = [];
        $captured = $pools && ! array_filter($pools, fn ($pool) => ! in_array($pool['connection'], (array) config('current.queue_connections', []), true));
        $add('capture_connections', $captured ? 'pass' : 'error', 'Every validated native queue connection must be included in current.queue_connections.');
        $phpredis = extension_loaded('redis') && config('database.redis.client', 'phpredis') === 'phpredis';
        $add('redis_client', $phpredis ? 'pass' : 'error', 'Native confirmation currently requires standalone phpredis.');
        foreach ($pools as $pool) { $redisNames[] = config('queue.connections.'.$pool['connection'].'.connection', 'default'); }
        if (config('current.native.registry.enabled', true)) { $redisNames[] = config('current.native.registry.redis_connection', 'default'); }
        if ($phpredis) {
            foreach (array_slice(array_unique($redisNames, SORT_REGULAR), 0, 33) as $index => $name) {
                $connection = null;
                try {
                    if ($index >= 32 || ! is_string($name) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)) { throw new \RuntimeException; }
                    $connection = ObservationRedis::connect($name);
                    if (! $connection->ping()) { throw new \RuntimeException; }
                    $add('redis_'.($index + 1), 'pass', 'Redis connection '.$name.' responds to a bounded PING.');
                } catch (Throwable) { $add('redis_'.($index + 1), 'error', 'A queue/registry Redis connection is unsupported or unreachable. Check configured connection names, standalone settings, credentials, TLS and ACLs.'); }
                finally { try { $connection?->disconnect(); } catch (Throwable) {} }
            }
        }
        $add('supervisor_binary', $this->binary(config('current.native.binary')) ? 'pass' : 'error', 'Supervisor must be a readable executable Linux ELF for this architecture. Header inspection does not verify its release checksum or protocol; install the matching release.');
        $directory = config('current.native.state_directory');
        if (! is_string($directory) || ! str_starts_with($directory, '/') || str_contains($directory, "\0") || is_link($directory)) {
            $add('state_directory', 'error', 'Configure an absolute private native state directory, not a symlink.');
        } elseif (is_dir($directory)) {
            $private = (fileperms($directory) & 0777) === 0700 && is_writable($directory) && (! function_exists('posix_geteuid') || fileowner($directory) === posix_geteuid());
            $add('state_directory', $private ? 'pass' : 'error', 'State directory must be owned by the worker user, writable and mode 0700. Run this check as that user.');
        } elseif (file_exists($directory)) {
            $add('state_directory', 'error', 'The native state path exists but is not a directory. Select a private directory; nothing was replaced.');
        } else {
            $parent = dirname($directory);
            while (! file_exists($parent) && dirname($parent) !== $parent) { $parent = dirname($parent); }
            $add('state_directory', is_dir($parent) && is_writable($parent) ? 'warning' : 'error', 'State directory is absent. The launcher must create it privately under a writable parent; this check creates nothing.');
        }
        $add('access_gate', Gate::has('viewCurrent') ? 'pass' : 'error', 'Define viewCurrent using your operator policy. Gate presence does not verify a particular user or web middleware.');
        if (config('current.allow_retries', true) || config('current.native.controls', false)) {
            $add('operator_gate', Gate::has('operateCurrent') ? 'pass' : 'warning', 'Define operateCurrent for retries and enabled controls; without it those actions remain denied.');
        }
        try {
            $name = config('current.connection');
            if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)) { throw new \RuntimeException; }
            $cfg = config('database.connections.'.$name);
            if (! is_array($cfg)) { throw new \RuntimeException; }
            if (($cfg['driver'] ?? null) === 'sqlite' && (! is_string($cfg['database'] ?? null) || ! is_file($cfg['database']))) { throw new \RuntimeException; }
            $db = app(Store::class)->connection(); $schema = $db->getSchemaBuilder();
            foreach (['lens_jobs', 'lens_attempts', 'lens_steps', 'lens_tags', 'lens_meta', 'lens_native_retry_claims', 'lens_native_metrics', 'lens_job_actions',
                'lens_async_receipts', 'lens_async_versions', 'lens_async_aliases', 'lens_webhook_receipts', 'lens_webhook_jobs', 'lens_webhook_deliveries',
                'lens_broadcasts', 'lens_broadcast_auth_failures'] as $table) { if (! $schema->hasTable($table)) { throw new \RuntimeException; } }
            foreach (['dispatch_id', 'cleared_at', 'arguments_state'] as $column) { if (! $schema->hasColumn('lens_jobs', $column)) { throw new \RuntimeException; } }
            $add('history_schema', 'pass', 'Current history tables and core identity columns are present (schema reads; SQL write permissions are not exercised).');
        } catch (Throwable) { $add('history_schema', 'error', 'History storage is unavailable or migrations are incomplete. Run current:install separately and confirm the dedicated connection and permissions.'); }
        $add('failed_provider', config('queue.failed.driver') === 'database-uuids' ? 'pass' : 'warning', 'Verified atomic native retry cleanup requires Laravel database-uuids failed storage; custom providers have unconfirmed cleanup limits.');
        $driver = config('current.telemetry.driver', 'sync');
        if ($driver === 'redis') {
            $add('collector_binary', $this->binary(config('current.telemetry.binary')) ? 'pass' : 'error', 'Collector executable must match Linux architecture. Use current:collector-install with the trusted checksum for release/protocol verification.');
            try {
                app(Transport::class)->settings(); // Validate privately; never output the credentials-bearing settings.
                $health = app(Health::class)->snapshot();
                $add('collector', ! empty($health['available']) && ! empty($health['running']) && empty($health['warning']) ? 'pass' : 'error', 'Async mode requires a running compatible healthy collector. Use current:telemetry-status; start it separately through your process manager.');
            } catch (Throwable) { $add('collector', 'error', 'Async transport configuration is invalid or its collector is unreachable. Review standalone Redis and dedicated SQL/TLS configuration.'); }
        } elseif ($driver === 'sync') { $add('telemetry', 'pass', 'Synchronous recording is selected; no collector is required.'); }
        else { $add('telemetry', 'error', 'Select a supported telemetry driver: sync or redis.'); }
        $add('deployment', 'warning', 'Manually confirm Horizon is drained/stopped, process-manager restart is disabled, scheduler is running and shared web/worker access is correct. Read checks cannot certify cutover safety, SQL write permissions, Redis mutation ACLs or matching binary releases.');
        return ['ready' => ! array_filter($checks, fn ($check) => $check['status'] === 'error'), 'checks' => $checks, 'scope' => 'Read checks in this process/user. No configuration edits, job dispatches, migrations or service controls. Database connections use application settings.'];
    }

    private function binary(mixed $path): bool
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || ! is_file($path) || ! is_readable($path) || ! is_executable($path)) { return false; }
        $header = @file_get_contents($path, false, null, 0, 64);
        $machine = match (php_uname('m')) { 'x86_64', 'amd64' => 62, 'aarch64', 'arm64' => 183, default => null };
        return is_string($header) && strlen($header) === 64 && substr($header, 0, 4) === "\x7fELF" && ord($header[4]) === 2 && ord($header[5]) === 1 && $machine !== null && unpack('v', substr($header, 18, 2))[1] === $machine;
    }
}
