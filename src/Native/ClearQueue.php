<?php

namespace Current\Native;

use Current\Support\DispatchIdentity;
use Illuminate\Support\Str;
use RuntimeException;

/** Point-in-time queue detachment, bounded projection and resumable acknowledgements. */
final class ClearQueue
{
    public function run(string $connection, string $queue, string $operation, bool $resume, bool $includingReserved = false, int $maxChunks = 0): array
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $connection) || ! preg_match('/^[a-zA-Z0-9_.:{}-]{1,100}$/D', $queue)
            || ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $operation]) || $maxChunks < 0
            || config('queue.connections.'.$connection.'.driver') !== 'current-redis'
            || ! in_array($connection, config('current.queue_connections', []), true)) {
            throw new RuntimeException('Choose an explicitly observed native connection and one queue.');
        }
        app(Maintenance::class)->assertCollectorCompatible();
        $driver = app('queue')->connection($connection);
        $redis = ObservationRedis::connect(config('queue.connections.'.$connection.'.connection', 'default'));
        $key = 'current:clear:'.hash('sha256', $connection.'|'.$queue).':'.$operation;
        $ready = $driver->getQueue($queue);
        $existing = $redis->get($key);
        if (is_string($existing)) {
            $legacy = json_decode($existing, true);
            if (($legacy['version'] ?? null) === 1) {
                if ($includingReserved) {
                    throw new RuntimeException('Resume the legacy operation without reserved removal.');
                }

                return $this->legacy($legacy, $operation, $connection, $queue);
            }
        }
        $keys = [$ready, $ready.':delayed', $ready.':reserved', $ready.':notify', $key, $key.':ready', $key.':delayed', $key.':reserved', $key.':active', $key.':receipt'];
        $actor = (string) Str::ulid();
        if (! $includingReserved) {
            $redis->watch($ready.':reserved');
        }
        $chunks = 0;
        try {
            do {
                $raw = $redis->eval(ClearScripts::step(), count($keys), ...array_merge($keys, [$operation, $connection, $queue, $resume ? '1' : '0', $includingReserved ? '1' : '0', $actor]));
                $receipt = $this->receipt($raw, $operation, $connection, $queue);
                $summary = $receipt['summary'];
                if (! $includingReserved && ! ($summary['reservations_verified'] ?? false) && $summary['phase'] === 'waiting') {
                    $proof = ($summary['index_owner'] ?? null) === $actor ? $redis->transaction(fn ($client) => $client->zcard($ready.':reserved')) : null;
                    $raw = $redis->eval(ClearScripts::verifyReservations(), 1, $key, is_array($proof) && count($proof) === 1 ? '1' : '0');
                    $summary = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                }
                if (isset($receipt['sequence'])) {
                    app(Maintenance::class)->cleared($connection, ['observed_at' => $summary['observed_at'], 'queue' => $queue, 'identities' => $receipt['identities']]);
                    $raw = $redis->eval(ClearScripts::ack(), 3, $key, $key.':receipt', $key.':active', (string) $receipt['sequence']);
                    $summary = $this->receipt(json_encode(['summary' => json_decode($raw, true, 16, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR), $operation, $connection, $queue)['summary'];
                }
                $resume = true;
                $chunks++;
            } while (! $summary['complete'] && ($maxChunks === 0 || $chunks < $maxChunks));
            ksort($summary);

            return $summary;
        } finally {
            if (! $includingReserved) {
                try {
                    $redis->unwatch();
                } catch (\Throwable) {
                }
            }
        }
    }

    private function legacy(array $record, string $operation, string $connection, string $queue): array
    {
        if (($record['operation'] ?? null) !== $operation || ($record['connection'] ?? null) !== $connection || ($record['queue'] ?? null) !== $queue || ! is_array($record['identities'] ?? null) || count($record['identities']) > 1000 || ! is_int($record['removed'] ?? null) || $record['removed'] < 0 || $record['removed'] > 1000 || ! is_numeric($record['observed_at'] ?? null) || ! is_finite((float) $record['observed_at']) || $record['observed_at'] <= 0 || $record['observed_at'] > microtime(true) + 300) {
            throw new RuntimeException('Invalid legacy clear receipt.');
        }
        foreach ($record['identities'] as $identity) {
            if (! is_array($identity) || ! is_string($identity['uuid'] ?? null) || $identity['uuid'] === '' || strlen($identity['uuid']) > 100 || ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $identity['dispatch'] ?? null])) {
                throw new RuntimeException('Invalid legacy clear identity.');
            }
        }
        app(Maintenance::class)->cleared($connection, $record);
        $summary = ['version' => 1, 'operation' => $operation, 'connection' => $connection, 'queue' => $queue, 'observed_at' => $record['observed_at'], 'removed' => $record['removed'], 'removed_waiting' => $record['removed'], 'removed_reserved' => 0, 'processed' => $record['removed'], 'untracked' => 0, 'complete' => true];
        ksort($summary);

        return $summary;
    }

    private function receipt(mixed $raw, string $operation, string $connection, string $queue): array
    {
        if (! is_string($raw) || strlen($raw) > 262144) {
            throw new RuntimeException('Clear outcome unconfirmed; retain the operation ID.');
        }
        $receipt = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $m = $receipt['summary'] ?? null;
        if (! is_array($m) || ($m['version'] ?? null) !== 2 || ($m['operation'] ?? null) !== $operation || ($m['connection'] ?? null) !== $connection || ($m['queue'] ?? null) !== $queue
            || ! is_bool($m['complete'] ?? null) || ! is_int($m['removed'] ?? null) || $m['removed'] < 0 || ! is_int($m['processed'] ?? null) || $m['processed'] < 0 || $m['processed'] > $m['removed']
            || ! is_numeric($m['observed_at'] ?? null) || ! is_finite((float) $m['observed_at']) || $m['observed_at'] <= 0 || $m['observed_at'] > microtime(true) + 300) {
            throw new RuntimeException('Invalid clear operation receipt.');
        }
        if (isset($receipt['sequence'])) {
            if (! is_int($receipt['sequence']) || $receipt['sequence'] < 1 || ! is_array($receipt['identities'] ?? null) || count($receipt['identities']) > 100) {
                throw new RuntimeException('Invalid clear chunk.');
            }
            foreach ($receipt['identities'] as $p) {
                if (! is_array($p) || ! is_string($p['uuid'] ?? null) || $p['uuid'] === '' || strlen($p['uuid']) > 100 || ! is_string($p['dispatch'] ?? null) || ($p['dispatch'] !== '' && ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $p['dispatch']]))) {
                    throw new RuntimeException('Invalid clear identity.');
                }
            }
        }

        return $receipt;
    }
}
