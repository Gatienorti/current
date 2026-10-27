<?php

namespace Current\Native;

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;

/** Isolate the inspected Laravel 11.44/12/13 database-UUID provider contract. */
final class FailedPayloads extends DatabaseUuidFailedJobProvider
{
    public static function forgetExact(object $provider, string $uuid, object $failed): bool
    {
        // The generic failed-provider contract has no compare-and-delete. Never
        // call an arbitrary provider's forget(UUID) after a concurrent enqueue.
        if (get_class($provider) !== DatabaseUuidFailedJobProvider::class) { return false; }
        $query = $provider->getTable();
        $driver = $query->getConnection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) { return false; }
        foreach (['uuid' => $uuid, 'connection' => $failed->connection, 'queue' => $failed->queue, 'payload' => $failed->payload] as $column => $value) {
            // MySQL's default text collation must not equate changed executable
            // bytes. SQLite's explicit BINARY also rejects custom NOCASE columns.
            $wrapped = $query->getGrammar()->wrap($column);
            if (in_array($driver, ['mysql', 'mariadb'], true)) { $query->whereRaw('BINARY '.$wrapped.' = BINARY ?', [$value]); }
            elseif ($driver === 'sqlite') { $query->whereRaw($wrapped.' = ? COLLATE BINARY', [$value]); }
        }
        return $query->delete() === 1;
    }
}
