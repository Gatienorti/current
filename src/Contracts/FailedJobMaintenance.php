<?php

namespace Current\Contracts;

/** Optional atomic/paginated capability for an application's custom Laravel failer. */
interface FailedJobMaintenance
{
    /** Stable store identity, excluding credentials (Current persists only its hash). */
    public function currentFailedMaintenanceScope(): string;

    /** A JSON-safe snapshot boundary, excluding subsequently logged/replaced failures. */
    public function currentFailedSnapshot(): array;

    /** @return array{jobs: array, cursor: mixed, complete: bool} At most $limit records. */
    public function currentFailedPage(array $snapshot, mixed $cursor, int $limit): array;

    /** Compare identity, connection, queue and executable payload atomically before deletion. */
    public function forgetCurrentFailedExact(string $id, object $expected): bool;
}
