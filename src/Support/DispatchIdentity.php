<?php

namespace Current\Support;

/** Internal observation identity; never replaces Laravel's UUID or Redis job ID. */
final class DispatchIdentity
{
    public static function fingerprint(string $uuid, string $connection, string $dispatch): string
    {
        // Byte-length framing is identical in PHP/Go, including Unicode and slashes.
        return hash('sha256', strlen($uuid).':'.$uuid.strlen($connection).':'.$connection.strlen($dispatch).':'.$dispatch);
    }

    public static function fromPayload(array $payload): ?string
    {
        $id = $payload['_current_dispatch_id'] ?? null;

        return is_string($id) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) ? $id : null;
    }
}
