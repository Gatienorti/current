<?php

namespace Current\Native;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** An authenticated, expiring cutoff prevents replay from retrying new dispatches. */
final class BatchRetryPass
{
    public function start(string $batchId): string
    {
        return Crypt::encryptString(json_encode(['batch' => $batchId, 'before' => substr((string) Str::ulid(), 0, 10), 'expires' => time() + 86400], JSON_THROW_ON_ERROR));
    }

    public function cutoff(string $token, string $batchId): string
    {
        try {
            $pass = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
            if (($pass['batch'] ?? null) === $batchId && is_int($pass['expires'] ?? null) && $pass['expires'] > time()
                && is_string($pass['before'] ?? null) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{9}$/D', $pass['before'])) {
                return $pass['before'];
            }
        } catch (\Throwable) {}
        abort(422, 'The batch retry pass is invalid or expired. Start a new pass from the batch page.');
    }
}
