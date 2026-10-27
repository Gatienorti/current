<?php

namespace Current\Native;

use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Throwable;

/** Read-only translation of the installed Horizon provisioning contract. */
final class HorizonMigration
{
    public function preview(?string $environment = null): array
    {
        $environment ??= config('horizon.env') ?: app()->environment();
        $report = ['environment' => null, 'matched_environment' => null, 'valid' => false, 'issues' => [], 'config' => null, 'queue_changes' => [], 'steps' => [
            'Review the preview and resolve every error. Existing configuration and services have not changed.',
            'Install the matching Linux supervisor; run current:install for Current history migrations.',
            'Copy the native settings into config/current.php and change only the listed queue connection drivers to current-redis.',
            'Define viewCurrent and operateCurrent using your application authorization policy. Assign a unique native instance and private state directory per worker instance.',
            'In a maintenance window, stop new producers and drain Horizon work, delayed jobs and reservations using your deployment process. Stop Horizon through its process manager and prevent automatic restart.',
            'Select CURRENT_EXECUTION_BACKEND=native, refresh configuration caches, and run current:doctor in the actual worker container/user. Do not run both engines on the same queues.',
            'Start the optional collector if selected, then current:supervise under your process manager. Restart producers, verify a synthetic job and monitor Current.',
            'Keep the original configuration for rollback: drain and stop native workers before restoring the Horizon backend and redis queue drivers. Existing native dispatch/retry identities are not retrofitted to old Horizon payloads.',
        ]];
        if (! is_string($environment) || ! preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $environment)) {
            return $this->issue($report, 'error', 'environment', 'Select a valid environment name.');
        }
        $report['environment'] = $environment;
        if (! class_exists(ProvisioningPlan::class)) { return $this->issue($report, 'error', 'horizon', 'The installed Horizon provisioning contract is required to preview its configuration.'); }
        $environments = config('horizon.environments'); $defaults = config('horizon.defaults', []);
        if (! is_array($environments) || count($environments) > 100 || ! is_array($defaults)) {
            return $this->issue($report, 'error', 'source', 'Horizon environments and defaults must be bounded configuration arrays.');
        }
        foreach ($environments as $pattern => $pools) {
            if (! is_string($pattern) || strlen($pattern) > 128) { return $this->issue($report, 'error', 'source', 'Invalid Horizon environment pattern.'); }
            if (Str::is($pattern, $environment)) { $report['matched_environment'] = $pattern; break; }
        }
        if ($report['matched_environment'] === null) { return $this->issue($report, 'error', 'environment', 'No Horizon environment pattern matches.'); }
        $selected = $environments[$report['matched_environment']];
        if (! is_array($selected) || count($selected) > 32 || count($defaults) > 32) {
            return $this->issue($report, 'error', 'pools', 'Select at most 32 supervisors per native instance.');
        }
        // Horizon uses recursive replacement here, including numeric queue/backoff list keys.
        $raw = array_replace_recursive($defaults, $selected);
        $allowed = ['connection', 'queue', 'balance', 'autoScalingStrategy', 'maxProcesses', 'minProcesses', 'balanceMaxShift', 'balanceCooldown', 'backoff', 'maxJobs', 'maxTime', 'memory', 'timeout', 'sleep', 'maxTries', 'force', 'nice', 'rest'];
        foreach ($raw as $name => $options) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) || ! is_array($options) || count($options) > 40) {
                return $this->issue($report, 'error', 'pools', 'Supervisor names/options cannot be represented by a native pool.');
            }
            $seen = [];
            foreach ($options as $key => $value) {
                if (! is_string($key) || strlen($key) > 64) { return $this->issue($report, 'error', 'options', 'Invalid supervisor option key.'); }
                $normalized = Str::camel(match ($key) { 'tries' => 'max_tries', 'processes' => 'max_processes', default => $key });
                if (! in_array($normalized, $allowed, true) || isset($seen[$normalized])) {
                    $report = $this->issue($report, 'error', 'options', 'Supervisor '.$name.' has an unsupported or ambiguous option; inspect its configuration manually.');
                }
                $seen[$normalized] = true;
                if (is_string($value) && strlen($value) > 4096) { $report = $this->issue($report, 'error', 'options', 'Supervisor '.$name.' has oversized option data.'); }
                if ($normalized === 'queue' && (! is_string($value) && ! is_array($value))) { $report = $this->issue($report, 'error', 'queues', 'Supervisor '.$name.' has invalid queue routing.'); }
                if (is_array($value) && (count($value) > 100 || array_filter($value, fn ($v) => ! is_scalar($v)))) { $report = $this->issue($report, 'error', 'options', 'Supervisor '.$name.' has invalid option values.'); }
                if (! is_scalar($value) && ! is_array($value) && $value !== null) { $report = $this->issue($report, 'error', 'options', 'Supervisor '.$name.' has non-data configuration.'); }
            }
        }
        if ($this->errors($report)) { return $report; }
        $native = ['state_directory' => storage_path('current/supervisor'), 'instance' => 'app-worker-a', 'binary' => storage_path('current/current-supervisor'),
            'grace_seconds' => 90, 'worker_limit' => 1, 'defaults' => [], 'environments' => [], 'pools' => []];
        try {
            $options = (new ProvisioningPlan('current-preview', [$report['matched_environment'] => $selected], $defaults))->parsed[$report['matched_environment']];
            $budget = 0;
            foreach ($options as $name => $option) {
                foreach (['maxProcesses', 'minProcesses', 'timeout', 'memory', 'maxTries', 'sleep', 'maxJobs', 'maxTime', 'nice', 'rest', 'balanceCooldown', 'balanceMaxShift'] as $property) {
                    $option->{$property} = $this->integer($option->{$property});
                }
                if ($option->maxProcesses < 0) { throw new \InvalidArgumentException; }
                if ($option->maxProcesses === 0) { $report = $this->issue($report, 'warning', 'disabled', 'Disabled supervisor '.$name.' is omitted.'); continue; }
                if (! is_string($option->connection) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $option->connection)) { throw new \InvalidArgumentException; }
                if (! is_string($option->queue) || strlen($option->queue) > 4096 || ! is_int($option->minProcesses)) { throw new \InvalidArgumentException; }
                $balance = match ($option->balance) { false, 'off', '' => 'fixed', 'simple' => 'simple', 'auto' => 'auto', default => throw new \InvalidArgumentException };
                $queues = explode(',', $option->queue);
                $minimum = $balance === 'fixed' ? $option->minProcesses : count($queues) * $option->minProcesses;
                // Explicit native startup allocation. Runtime/transient balancing is not promised identical.
                $workers = max($minimum, (int) floor(($option->minProcesses + $option->maxProcesses) / 2));
                $backoff = $option->backoff;
                if (is_string($backoff)) {
                    if (! preg_match('/^\d+(,\d+)*$/D', $backoff)) { throw new \InvalidArgumentException; }
                    $backoff = array_map('intval', explode(',', $backoff));
                }
                $pool = ['connection' => $option->connection, 'queues' => $queues, 'balance' => $balance,
                    'workers' => $workers, 'min_workers' => $option->minProcesses, 'max_workers' => $option->maxProcesses,
                    'timeout' => $option->timeout, 'memory' => $option->memory, 'tries' => $option->maxTries,
                    'sleep' => $option->sleep, 'backoff' => $backoff, 'max_jobs' => $option->maxJobs, 'max_time' => $option->maxTime,
                    'force' => $option->force, 'nice' => $option->nice, 'rest' => $option->rest,
                    'balance_cooldown' => $option->balanceCooldown, 'balance_shift' => $option->balanceMaxShift];
                if ($pool['timeout'] < 1 || $pool['timeout'] > 299) {
                    $report = $this->issue($report, 'error', 'timeout', 'Supervisor '.$name.' has timeout '.$pool['timeout'].'s, outside the native 1–299 second limit. It has not been shortened; keep this workload on Horizon until that requirement is supported or explicitly changed.');
                }
                if ($minimum > $pool['max_workers']) {
                    $report = $this->issue($report, 'error', 'worker_range', 'Supervisor '.$name.' requires at least '.$minimum.' workers for its queue minima but has maximum '.$pool['max_workers'].'. Review the queue count and worker budget; no allocation is silently increased.');
                }
                if ($balance === 'auto') { $pool['auto_scale_strategy'] = $option->autoScalingStrategy; $pool['balance_policy'] = 'horizon'; }
                $driver = config('queue.connections.'.$pool['connection'].'.driver');
                if (! in_array($driver, ['redis', 'current-redis'], true)) { throw new \InvalidArgumentException; }
                $report['queue_changes'][$pool['connection']] = ['from' => $driver, 'to' => 'current-redis'];
                $native['pools'][$name] = $pool;
                $budget += $option->maxProcesses;
                if (! is_int($pool['timeout'])) { throw new \InvalidArgumentException; }
                $native['grace_seconds'] = max($native['grace_seconds'], min(300, $pool['timeout'] + 30));
            }
            $native['worker_limit'] = $budget;
            if (! $native['pools']) { throw new \InvalidArgumentException; }
            if ($budget > 256) { $report = $this->issue($report, 'error', 'worker_budget', 'The selected supervisors exceed the 256-worker native instance budget. Split them into reviewed separate instances; no maximum is silently reduced.'); }
            if ($this->errors($report)) { $report['queue_changes'] = []; return $report; }
            // Validate with the same native contract as startup. Restore every touched config value.
            $keys = ['current.native', 'current.execution_backend'];
            foreach (array_keys($report['queue_changes']) as $connection) { $keys[] = 'queue.connections.'.$connection.'.driver'; }
            $saved = array_combine($keys, array_map(fn ($key) => config($key), $keys));
            try {
                config()->set('current.native', $native); config()->set('current.execution_backend', 'native');
                foreach (array_keys($report['queue_changes']) as $connection) { config()->set('queue.connections.'.$connection.'.driver', 'current-redis'); }
                app(Plan::class)->workerPools();
            } finally { config()->set($saved); }
            $report['config'] = ['native' => $native, 'queue_connections' => array_keys($report['queue_changes'])];
            $report['valid'] = true;
            $report = $this->issue($report, 'warning', 'semantics', 'Review initial worker counts, per-queue minima and the derived grace/budget. Transient balancing, metric windows, retention and retry identities differ from Horizon.');
            $report = $this->issue($report, 'warning', 'manual', 'Retention, monitored tags, silencing, wait alerts and notification routes need explicit Current configuration; they are not imported. Merge queue_connections with existing capture connections.');
        } catch (Throwable) {
            $report = $this->issue($report, 'error', 'validation', 'The selected supervisors cannot satisfy the native pool, worker budget, retry_after or timeout limits. Review the source settings with the native setup guide.');
            $report['queue_changes'] = [];
        }
        return $report;
    }

    private function issue(array $report, string $level, string $code, string $message): array
    {
        $report['issues'][] = compact('level', 'code', 'message');
        return $report;
    }

    private function errors(array $report): bool { return (bool) array_filter($report['issues'], fn ($issue) => $issue['level'] === 'error'); }

    private function integer(mixed $value): int
    {
        if (is_int($value)) { return $value; }
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer !== false) { return $integer; }
        }
        throw new \InvalidArgumentException;
    }
}
