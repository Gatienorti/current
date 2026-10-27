# Compatibility

## Requirements

| Dependency | Supported range |
| --- | --- |
| PHP | `^8.2`; Laravel 13 requires PHP 8.3+ |
| Laravel / Illuminate | `^11.44`, `^12.0`, `^13.0` |
| Horizon, for the default backend | `^5.30`, with a compatible Laravel version |
| PHP-Parser | `^5.7` |

Horizon is optional only when explicitly selecting Current's experimental native execution backend. Composer must satisfy each dependency's own constraints: Horizon 5.30 pairs with Laravel 11; the Laravel 12 lower-bound fixture uses Horizon 5.31. Laravel 10 and earlier versions than the declared floors are not supported.

Laravel 11.44 is the tested floor for the attempt lifecycle events Current needs. Historical Laravel 11.44/12.0 test consumers pin Symfony Console ~7.3 because their command registration is incompatible with Console 7.4. This is a historical test-fixture constraint, not a recommended host pin. Keep your application dependencies on mutually compatible, maintained versions.

## Tested native profiles

These clean consumers were tested locally without Horizon, with both synchronous recording and the optional async collector:

| PHP | Laravel |
| --- | --- |
| 8.2.35 | 11.44.0 |
| 8.2.35 | 12.0.0 |
| 8.3.36 | 11.57.0, 11.57.1 |
| 8.3.36 | 12.70.1, 12.70.2 |
| 8.3.36 | 13.38.0, 13.39.0 |
| 8.4.23 | 13.38.0, 13.39.0 |

Coverage includes installation, retry/release behavior, worker loss, transaction boundaries, named pools, controls, retained history and dashboard access. These are selected Linux ARM64 / standalone Redis / SQLite combinations, not every possible version pairing or production topology. The newer per-queue balancing and batch/metric operations also passed fresh consumers on Laravel 11.44 and 12.0/PHP 8.2, and Laravel 13.39/PHP 8.3. Concurrent native batch retry checks passed with MySQL 8.4 and MariaDB 11.4 application batch storage. The native hosted workflow runs the configured profiles on GitHub; inspect its checks for the revision you plan to deploy. Native execution remains experimental; see [setup and limitations](NATIVE-ENGINE.md).

The default Horizon compatibility matrix is defined in `tests/compatibility/matrix.json` and `.github/workflows/compatibility.yml`. Historical fixtures may permit Composer advisories solely in disposable test consumers; that is not a recommendation to deploy vulnerable dependency versions.

## Storage and transport limits

- Synchronous history has been tested with SQLite, MySQL 8.4 and MariaDB 11.4. Use a dedicated history connection outside application-owned transactions. Server-SQL concurrency checks cover producer/worker races; SQLite still serializes its writers.
- Async job telemetry requires Linux, phpredis, standalone Redis 7, and either local SQLite or MySQL 8.4/MariaDB 11.4 InnoDB. See [async setup](ASYNC-TELEMETRY.md) for TLS, ownership, supervision and recovery requirements.
- PostgreSQL, Redis Cluster/Sentinel, SQL table prefixes, SQL URLs/read-write splitting and unsupported PDO options are outside the async support boundary.
- Native enqueue confirmation is verified with standalone phpredis. Predis, Redis Cluster, caller-owned pipelines and distributed coordination remain unsupported or unvalidated.
- Local SQLite must not be hosted on a shared/network filesystem for the collector.
- Async protocol 2 removes job-identity SQL reads from producers. MySQL/MariaDB network-pause checks verify buffered recovery; stopped-producer upgrades and matching collector/migrations are required. Native time-balancing/workload reads use short-lived Redis observations; SQL sampling is isolated behind a process timeout. This does not make application SQL or the separate webhook/broadcast recorders asynchronous.

Passing finite tests does not establish production-scale performance, losslessness during Redis data loss, exactly-once external effects or unlimited outage recovery.

## Run compatibility checks

See [contributing](../CONTRIBUTING.md) for the portable suite. Native checks use fresh temporary Composer consumers, a disposable Redis server and built supervisor/collector binaries:

```sh
NATIVE_REDIS_HOST=127.0.0.1 php tests/native/install.php '^12.0' local-native sync,redis
```

The test harness never requires private application source. Keep generated screenshots, benchmark output and verification reports local; they are intentionally excluded from Git.

The isolated runtime-sampler and deployment-termination slice also passed clean Laravel12.0/PHP8.2 and Laravel13.39/PHP8.3 native consumers in both recording modes, including proportional balancing and batch/metric operations. SQL network-pause checks on MySQL8.4 and MariaDB11.4 verify bounded sampling and recovery. These finite checks do not establish production multi-host reliability.


Native maintenance uses migration16 and a matching collector with maintenance protocol1. Queue-clear/history reconciliation and forgotten replay suppression passed SQLite plus live MySQL8.4/MariaDB11.4 collector contention checks. Clean Laravel12.0/PHP8.2 and Laravel13.39/PHP8.3 consumers exercise the public commands in both telemetry modes. Queue clearing has explicit entry/payload bounds and preserves reservations; see the native operation guide. These are scoped acceptance results, not production-scale or Redis failover guarantees.

The final local clean-consumer pass also covers Laravel 11.57.1, 12.70.2 and 13.39.0 with PHP 8.3.36, alongside both historical floors on PHP 8.2.35 and Laravel 13.39.0/PHP 8.4.23. Native runs include busy graceful/forced shutdown, launcher loss, stopped-producer telemetry transitions, balancing and guarded maintenance. The default Horizon matrix passes across its six declared profiles. Collector maintenance locking is verified with READ COMMITTED session/server defaults and mutual TLS on both supported SQL servers. The beta.2 hosted native and default Horizon matrices also pass; supervisor tests and binary builds pass on actual AMD64 and ARM64 runners.

Development changes after beta.2 require migration 17 and a matching supervisor binary. Optional wait notifications have been checked with Laravel 12, Slack 3.10 and Vonage 3.3 using fake transports; real provider delivery and other channel-version combinations remain unvalidated. Orphan cleanup requires usable Linux pidfds and `/proc` identity evidence. Worker startup can fall back on supported pidfd capability errors; cleanup never falls back to raw PID signaling. See the native guide for exact scope.
