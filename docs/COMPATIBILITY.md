# Compatibility

Current is an application-independent Horizon companion. Vehify and ADAPT supply integration examples, not package version pins. No host application upgrade is required solely to match Vehify's patch release.

## Declared range

| Dependency | Composer range |
| --- | --- |
| PHP | `^8.2` (Laravel 13 itself requires PHP 8.3+) |
| Laravel / Illuminate | `^11.44 \|\| ^12.0 \|\| ^13.0` |
| Horizon | `^5.30` |
| PHP-Parser | `^5.7` |

Composer must also satisfy Horizon's own Laravel constraints. This does not mean every Horizon version pairs with every Laravel version: Horizon 5.30 supports Laravel 11, while the Laravel 12 lower-bound fixture uses Horizon 5.31. Laravel 10, Laravel 11 before 11.44, Horizon before 5.30, and future major releases are not claimed here. No installation-share data was collected, so “most Horizon installations” is a product goal, not a measured coverage percentage.

The Laravel floor is the first tested baseline for the existing attempt recorder. Laravel 11.0 does not emit `JobAttempted`; registering a nonexistent event would silently lose completion observations. Older event lifecycles need a separately verified adapter before lowering this floor. Horizon 5.23–5.29 have an incompatible `RedisQueue::pop` signature with Laravel 11.44; Horizon 5.30 accepts the added index argument. The package continues to use public events and repository contracts, with optional dashboard-source failures reported as unavailable.

## Verification scope

**VERIFIED locally on 2026-10-25:** after the installation/automatic-data pass, each combination below passed **52 tests / 517 assertions** on PHP **8.4.23**, real Redis, and SQLite after a fresh Composer path-consumer resolution. These are tested pairs, not every patch permutation within the declared ranges.

| Laravel | Horizon | Symfony Console | Profile |
| --- | --- | --- | --- |
| 11.44.0 | 5.30.0 | 7.3.10 | Historical lower bound |
| 11.57.0 | 5.50.1 | 7.4.21 | Current 11.x resolution |
| 12.0.0 | 5.31.0 | 7.3.10 | Historical lower bound |
| 12.62.0 | 5.47.2 | 7.4.21 | ADAPT version pair |
| 12.69.3 | 5.47.2 | 7.4.21 | Vehify version pair |
| 13.38.0 | 5.50.1 | 8.1.9 | Current 13.x resolution |

The clean consumers resolved PHP-Parser 5.9.0 and PHPUnit 11.5.57. Their ADAPT/Vehify labels refer only to the framework/Horizon version pairs, not those applications' complete lockfiles or runtime behavior. The live lab retains its existing dependency tree; its full suite, including the actual mocked Vehify job, passed 53 tests / 527 assertions after the setup pass. The actual ADAPT application suite passed seven tests / 117 assertions. The six-profile run includes the new additive migration, `JobQueued` capture, redaction/limits/failure isolation and rendered detail checks; browser interaction checks remain separate.

Subsequent actual ADAPT application tests boot its normal providers and run three real job classes with fixture data and mocked external services. Seven tests / 112 assertions pass on its installed PHP-Parser 5.7.0 and PHPUnit 12.5.12. This extends evidence beyond the matrix's version-only ADAPT profile and permits the lower parser constraint without upgrading the application.

The recorder uses `JobAttempted::successful()` after checking failure/release flags. Laravel 13 replaces the earlier `exceptionOccurred` property with an exception object; directly reading the old property left successful jobs stuck in processing. The shared public method preserves lifecycle capture across the tested versions.

The matrix in [tests/compatibility/matrix.json](../tests/compatibility/matrix.json) includes lower-bound Laravel 11/12 combinations, current Laravel 11/13 resolution, and the Laravel/Horizon versions used by ADAPT and Vehify. Each row creates a fresh temporary consumer, resolves Current through a Composer path repository, and executes the portable lifecycle, filtering, navigation, authorization, retention, timeout, worker-loss, and concurrency tests against Redis and SQLite. It does not load either host application's providers, environment, database, or normal jobs.

The optional `vehify` test group runs separately against its existing read-only vendor tree. It is excluded from portable runs because a standalone package must not require that application's job class. The test harness isolates both normal Redis keys and Horizon's copied connection prefix; setting only `horizon.prefix` after provider boot was insufficient to isolate metrics.

The matrix exposed a real storage difference: Laravel 11's SQLite connection ignores the newer `transaction_mode` option. A deferred read followed by a write could lose an observation under concurrency even with a busy timeout. Current now acquires a SQLite write lock with a no-op metadata UPDATE before reading in recorder/prune transactions, while retaining Laravel transaction handling. The simultaneous-worker fixtures exercise this path. Timeout bounds still depend on host connection configuration; no production throughput or latency claim follows from these tests.

Historical Laravel 11.44 / 12.0 fixtures pin Symfony Console to 7.3. Their Artisan command registration fails with Console 7.4 despite the old manifests allowing that combination. This is an upstream compatibility constraint, not a Current adapter. Existing installations must retain a mutually compatible Laravel/Symfony set; the current-release matrix also tests normal dependency resolution.

Historical fixture resolution explicitly permits packages flagged by Composer advisories only inside the disposable test consumers, with audit reporting retained. The package manifest and host security policies are not relaxed. Passing compatibility tests does not recommend deploying historical vulnerable releases.

Local runtime results are recorded in the plan. PHP 8.2/8.3 jobs are configured in [the CI workflow](../.github/workflows/compatibility.yml); configuration alone is not execution evidence. The current local matrix uses PHP 8.4.23. A fresh Laravel 13.38/Horizon 5.50.1 app passed normal Composer discovery, repeated installation, authorization, default SQLite and MySQL 8.4 strict-mode lifecycle/detail/pruning checks, cached config/routes, and package removal preserving configuration/history. MySQL strict grouping exposed and verified a fix to the dashboard counts query. Other PHP runtimes, MySQL concurrency/load and in-place host installation remain separate validation work. See [Installation](INSTALLATION.md).

## Source-cache regression check

**VERIFIED 2026-10-25:** after worker-local source caching, the original package suite passes **60 tests / 581 assertions**, actual ADAPT through Composer discovery passes **7 / 117**, and the oldest supported Laravel 11.44/Horizon 5.30 profile passes **59 / 571**, all on PHP 8.4.23. These runs include content invalidation, bounded eviction and failure isolation. The other five profiles above retain their earlier installation evidence and were not repeated for caching. ADAPT continues to exercise PHP-Parser 5.7.0.

## MariaDB verification

**VERIFIED on 2026-10-25:** MariaDB **11.4.13** (`mariadb:11.4`, image digest `sha256:250e1609e93c0e6aecf64bfbab2d84d5e89d2481540fe77447ff6c855c3b1873`) passes all **17 installation/lifecycle smoke checks** through both Laravel's `mariadb` and `mysql` drivers. Consumer: Laravel 13.38.0, Horizon 5.50.1, PHP 8.4.23. Session SQL mode includes `ONLY_FULL_GROUP_BY` and `STRICT_TRANS_TABLES`.

Checks cover initial/repeated installation, Current route registration, anonymous denial/authorized access, real Redis job completion and terminal retry, three attempts/six named steps, automatic Args and pre-storage redaction, job-detail rendering, populated dashboard aggregates, intersected status/queue filters and retention pruning. The native driver creates the schema; the MySQL-driver run reuses that schema and verifies repeat installation and the same lifecycle. No application-code fix was necessary. The enhanced smoke fixture also passes against SQLite. Existing package/ADAPT/version-matrix totals above were not rerun for this test-only change.

This is one tested MariaDB server/framework combination, not a guarantee for all MariaDB versions or production concurrency/load. The database ran on the isolated lab network with no published ports and was removed afterward; neither host application was modified.

## Repeat the checks

Inside the isolated lab:

```sh
docker compose -f compose.prototype.yaml exec -T lens-web php tests/compatibility/run.php
docker compose -f compose.prototype.yaml exec -T lens-web php tests/compatibility/run.php adapt
```

Outside Docker, use PHP with PCNTL, Redis, PDO SQLite, DOM and mbstring, Composer 2.9+, and a disposable Redis instance. Set `LENS_REDIS_HOST` to that instance, then run `php tests/compatibility/run.php`. The script prints its temporary directories and retains resolved lockfiles for inspection. It does not alter the root manifest or either application's lockfile. The GitHub Actions workflow is prepared locally and has not been published or executed remotely.

Source references: [Laravel 12 worker events](https://github.com/laravel/framework/blob/v12.0.0/src/Illuminate/Queue/Worker.php), [Horizon 5.30 queue adapter](https://github.com/laravel/horizon/blob/v5.30.0/src/RedisQueue.php), [Horizon 5.31 constraints](https://github.com/laravel/horizon/blob/v5.31.0/composer.json), [Laravel 13 requirements](https://github.com/laravel/framework/blob/13.x/composer.json).

Latest retry/live-view regression (2026-10-25): **VERIFIED** all six profiles pass 63 portable tests / 611 assertions each, including native Horizon retry payload preparation, lineage, duplicate-submit protection, authorization/CSRF and ambiguous transport handling. The original Vehify-backed suite passes 64 / 621; actual ADAPT Composer-discovery integration passes 7 / 117. These supersede earlier suite counts without broadening runtime/database claims.

## Fresh beta consumer acceptance — 2026-10-25

A new `composer create-project laravel/laravel` consumer resolved Laravel 13.38.0 and Horizon 5.50.1 with PHP 8.4.23 in the isolated lab. Current installed through its local Composer path repository, discovered normally, applied all 11 migrations and passed jobs-only acceptance both before and after config/route caching. This is focused installation evidence, not a new full compatibility-matrix profile.

Adding Reverb afterward required `--with-all-dependencies` in this fresh consumer: its initial Guzzle 8.3.1 / PSR-7 3.2.0 lock did not satisfy Reverb's PSR-7 2.x requirement. Composer resolved Reverb 1.12.0 with Guzzle 7.16.1 / PSR-7 2.13.2. Optional synthetic incoming receipts → queued jobs → mocked outgoing retry groups / real loopback Reverb submissions, channel-auth rejection, comparison/prompt rendering and pruning then passed. No package runtime code needed changing. Existing application lockfiles were untouched; this does not authorize equivalent dependency changes in a host app.

### Optional async telemetry

The synchronous database matrix does not imply async collector support. Async support is Linux, phpredis, standalone Redis 7, and either SQLite on a local Linux filesystem or MySQL 8.4/MariaDB 11.4 with InnoDB. SQL table prefixes, SQL URLs/read-write splitting/unsupported PDO options, PostgreSQL, Redis Cluster/Sentinel and host-shared/network SQLite files are outside this opt-in boundary. See [setup and limitations](ASYNC-TELEMETRY.md). The compatibility workflow now builds the optional collector before testing consumers; new hosted results are not implied until CI actually runs. Local async integration evidence is recorded in PLAN.md.

**VERIFIED async server-database checks, 2026-10-26:** MySQL **8.4.10** and MariaDB **11.4.13**, each 13 tests / 124 assertions on Laravel 12.69.3, Horizon 5.47.2 and PHP 8.4.23. MariaDB passed with both Laravel's `mariadb` and `mysql` drivers. The tests include real retries, deferred writes, Unicode/redaction, reverse snapshots and duplicate replay, database-lock recovery, whole-batch rollback, SQL session loss, competing collectors/namespaces, InnoDB enforcement and pruning. These are isolated functional checks, not throughput or production durability evidence. Custom PDO options outside the supported TLS set and topology/failover configurations are not validated. The new hosted database CI jobs have not yet run.
