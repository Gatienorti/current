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

The unreleased lightweight native launcher was checked locally on Laravel 11.44/PHP 8.2 with Predis 2.3, Laravel 12.0/PHP 8.2 with phpredis, Laravel 13.39/PHP 8.3 with Predis 3.3, and Laravel 13.39/PHP 8.4 with phpredis, using both telemetry modes. The PHP 8.4 consumer additionally verifies automatic handoff, explicit opt-out, `--env` and PHP startup-flag fallbacks, and clean shutdown. MySQL 8.4 and MariaDB 11.4 runtime-reader transfer passed plain and mutually authenticated TLS connections. These are compatibility checks, not new throughput measurements; see the [launcher requirements and opt-out](NATIVE-ENGINE.md#lightweight-launcher-unreleased).

The default Horizon compatibility matrix is defined in `tests/compatibility/matrix.json` and `.github/workflows/compatibility.yml`. Historical fixtures may permit Composer advisories solely in disposable test consumers; that is not a recommendation to deploy vulnerable dependency versions.

## Storage and transport limits

- Synchronous history has been tested with SQLite, MySQL 8.4 and MariaDB 11.4. Use a dedicated history connection outside application-owned transactions. Server-SQL concurrency checks cover producer/worker races; SQLite still serializes its writers.
- Async job telemetry requires Linux, phpredis or optional Predis 2.3/3.x, standalone Redis 7, and either local SQLite or MySQL 8.4/MariaDB 11.4 InnoDB. See [async setup](ASYNC-TELEMETRY.md) for TLS, ownership, supervision and recovery requirements.
- PostgreSQL, Redis Cluster/Sentinel, SQL table prefixes, SQL URLs/read-write splitting and unsupported PDO options are outside the async support boundary.
- Native enqueue confirmation supports standalone phpredis and optional Predis 2.3/3.x. Caller-owned pipelines/transactions are left under caller ownership and establish no fabricated enqueue confirmation; worker start-only history remains available. Redis Cluster and distributed coordination remain unsupported or unvalidated.
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

Beta.3 additions require migrations 17–20 and matching supervisor/collector binaries. Optional wait notifications have been checked with Laravel 12, Slack 3.10 and Vonage 3.3 using fake transports; real provider delivery and other channel-version combinations remain unvalidated. Orphan cleanup requires usable Linux pidfds and `/proc` identity evidence. Worker startup can fall back on supported pidfd capability errors; cleanup never falls back to raw PID signaling. See the native guide for exact scope.


## Beta.3 capability coverage

Local dual-client checks cover Predis 2.3.0 and 3.3.0 with phpredis comparison, direct/delayed/bulk execution, owned and caller-owned transactions/pipelines, partial errors, lost EXEC acknowledgement, private bounded observation, authentication, observer failure isolation, and real async PHP→Go projection. Disposable mutual-TLS Redis checks cover CA/client certificate/key/peer-name verification, wrong-CA rejection and old binary refusal. A separate per-process PHP configuration without ext-redis runs the actual Predis paths; phpredis-only cases are scoped skips. These checks do not establish Redis Cluster/Sentinel or every network failure mode. New hosted Predis floor/current consumers are maintained alongside the existing matrix.

The native baseline now includes managed monitored tags with full detail retention, wrapper-aware silencing, point-in-time queue wait history, resumable queue/failed maintenance, deployment-independent inventory/controls, zero-worker floors and paused startup, worker names/output, provisioning environment selection, optional dashboard domains and orchestration memory limits. The Linux supervisor remains an optional backend; macOS/Windows native binaries and broad production reliability are not claimed. Larger plan validation checks configuration and strict budgets; it is not a thousands-of-workers throughput benchmark.

Install migrations 17–20 and matching beta.3 binaries for these additions. Custom Redis TLS requires redis_tls capability 1; async monitored retention requires monitored_retention 1. Existing ordinary protocol 2 traffic remains compatible only within its documented supported capability boundary.

Dynamo failed-storage validation uses real Laravel 12/PHP 8.3/AWS SDK 3.402.2 against disposable DynamoDB Local 3.3.2: 5 tests / 89 assertions cover filtered pagination/cursors, scope, exact conditions, same-second exception/TTL replacements and missing/added fields. The optional SDK remains an application dependency; a dedicated hosted job exercises this adapter. Actual AWS IAM, service consistency and TTL timing are not certified by an emulator.

Cold zero-worker recovery is checked with actual Go owners and separate128MiB Laravel workers: known ready, due delayed and expired-reservation work completes without runtime history on both Redis clients. Future work stays excluded; no runtime or ready metric is fabricated. Predis2.3/3.3 and phpredis also reject unknown URL schemes and preserve explicit TLS requests instead of falling back to plaintext.

### Attempt policy snapshots

Migration 20 adds nullable per-attempt policy JSON. New standard Laravel/Horizon worker commands capture numeric policy and provenance without changing execution. Missing command context and old attempts remain unknown. Async collection uses existing dynamic schema discovery: apply migrations and restart the collector before upgraded producers/workers. Local tests cover Laravel worker options, Horizon worker options, job overrides, unknown historical values, conditional notices, and real collector projection. Full cross-version hosted CI for this subsequent UI/policy change has not yet run.

The lightweight native runtime reader was checked on fresh Laravel11.44/PHP8.2, Laravel12.0/PHP8.2 and Laravel13.39/PHP8.3 installs, including private-pipe reads, custom-extension fallback, runtime estimates, autoscaling and memory diagnostics. Local MySQL8.4/MariaDB11.4 network-pause checks confirm bounded sampling and recovery. Standard connections use Laravel’s database factory without application/provider bootstrap; custom manager/connector/connection/grammar extensions keep the existing bootstrap path. No new TLS-specific cohort or hosted matrix was run for this slice.
