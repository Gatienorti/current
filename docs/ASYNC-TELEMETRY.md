# Opt-in asynchronous job telemetry

Current defaults to synchronous recording. An optional Redis transport and Go collector move **job, attempt and step SQL writes** out of Laravel workers. Execution stays with the selected backend: Horizon by default, or the optional native supervisor. Existing Args/Metadata sanitization, source inspection and the Redis acceptance round trip still happen in PHP. Webhook and broadcast recording stay synchronous.

## Initial support boundary

The opt-in implementation targets **Linux, PHP with phpredis or optional Predis 2.3/3.x and standalone Redis 7**, with one of:

- **SQLite** on a local Linux filesystem.
- **MySQL 8.4 or MariaDB 11.4**, with Current tables using InnoDB and one explicit server connection.
- **PostgreSQL 17/18** (unreleased), with permanent, unpartitioned tables in the `public` schema and one direct TCP connection.

Current uses the database configured by `current.connection`; it does not move existing history or change the application's default database. MySQL/MariaDB credentials are passed to the collector over a private pipe. The collector uses a pinned SQL connection, UTF-8 (`utf8mb4`), strict writes and a one-second InnoDB lock-wait timeout. Its own transactions explicitly request `REPEATABLE READ` so maintenance locking does not depend on a server's default isolation. Application sessions and server defaults are unchanged. It never uses `INSERT IGNORE`, which could hide truncation or other write errors.

SQL URL-style connections, read/write splitting and table prefixes are rejected rather than silently ignored. The collector supports Laravel PDO TLS options `MYSQL_ATTR_SSL_CA`, `MYSQL_ATTR_SSL_CERT`, `MYSQL_ATTR_SSL_KEY` and `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`; other PDO options remain unsupported. TLS verifies the server certificate and hostname, requires TLS 1.2+, supports private CAs and mutual TLS, and never falls back to plaintext. Disabling verification is rejected. Certificate paths must be readable by both the PHP workers and the collector; configure the SQL host to match the certificate. A client certificate and key must be supplied together. Redis Cluster/Sentinel, SQLite memory databases, network filesystems and Docker Desktop host-shared database files are outside the validated boundary. The SQLite lock/recovery tests pass on container-local storage; a Docker Desktop bind-mounted fixture did not enforce the expected cross-process lock behavior.

Use one collector per Current database/namespace. Async collection and the optional native supervisor are independent choices. Keeping the default Horizon backend retains Horizon execution, balancing and retries; selecting native execution uses the explicitly configured Current engine.

## PostgreSQL history (unreleased)

Install PHP's `pdo_pgsql` extension. Define a dedicated Laravel connection in `config/database.php` and select it with `CURRENT_CONNECTION=current_pgsql`:

```php
'current_pgsql' => [
    'driver' => 'pgsql',
    'host' => env('CURRENT_DB_HOST', '127.0.0.1'),
    'port' => env('CURRENT_DB_PORT', 5432),
    'database' => env('CURRENT_DB_DATABASE', 'current'),
    'username' => env('CURRENT_DB_USERNAME', 'current'),
    'password' => env('CURRENT_DB_PASSWORD'),
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'public',
    'sslmode' => 'verify-full',
    'sslrootcert' => env('CURRENT_DB_SSLROOTCERT'),
    // Optional mutual TLS; provide both together:
    'sslcert' => env('CURRENT_DB_SSLCERT'),
    'sslkey' => env('CURRENT_DB_SSLKEY'),
],
```

Create the database and grant its application role permission to install and maintain Current's tables. Run `php artisan current:install`, then restart workers. This configuration also works with synchronous recording; Redis remains the queue backend. Changing the history connection does not transfer existing records.

Async mode needs a matching collector built from this source with `postgres: 1` in `--version`; released beta.3 binaries do not support PostgreSQL. Build/update the binary before enabling this connection. No additional schema migration is needed beyond the existing package migrations.

The collector verifies hostname and certificate with `verify-full`; use explicit `sslmode=disable` only for an intentionally unencrypted local/trusted connection. `prefer`, `allow`, `require` and `verify-ca` are rejected because they do not provide the same no-downgrade, hostname-verified contract. Certificate files must be readable by both processes; libpq requires a private client key (normally mode `0600`). Custom PDO options, schemas/search paths, Unix sockets, connection URLs, read/write splitting and transaction-pooling proxies are outside the async boundary. Use a direct primary connection: collector ownership is session-scoped.

PostgreSQL uses [transaction advisory locks](https://www.postgresql.org/docs/current/explicit-locking.html#ADVISORY-LOCKS) shared by PHP and Go to serialize absent maintenance identities, plus a session advisory lock for the single collector. Transactions explicitly use `READ COMMITTED`, a one-second lock wait in the collector, and a five-second collector statement/socket timeout. Unlogged/temporary history tables are rejected. Lost collector sessions require restart and reacquisition of ownership; committed receipts make replay idempotent. These guarantees do not imply exactly-once business effects or recovery from Redis data loss.

## Install and start

1. Choose the existing Laravel history connection with `CURRENT_CONNECTION` (for example, a dedicated `current_mysql` connection), then run Current's package migrations with the normal installer. Keep the existing setting if you already have history; changing this setting does not migrate old records:

   ```sh
   php artisan current:install
   ```

2. Install a matching collector binary. The [Collector binaries workflow](https://github.com/Gatienorti/current/actions/workflows/collector-binaries.yml) produces static Linux `amd64` and `arm64` executables plus SHA-256 files, available as workflow artifacts and [release assets](https://github.com/Gatienorti/current/releases). Use beta.2 or later; beta.1 predates this feature.

   Download the binary and its checksum file from the same trusted Current release/workflow run, then install it from your application:

   ```sh
   php artisan current:collector-install ./current-collector-linux-amd64 --sha256="$(cut -d ' ' -f 1 SHA256SUMS-linux-amd64)"
   ```

   Use `arm64` on ARM servers. Run the installation command as the application user that will run the collector. The command verifies the checksum, executable protocol and architecture before atomically installing at `current.telemetry.binary`. It refuses to replace an existing binary unless you stop the collector and pass `--force`. It does not enable async recording. A checksum downloaded from the same release detects corruption; it is not an independent signature or trust source.

   Alternatively, build the collector on the target Linux architecture. It requires Go 1.25+ and a C compiler (CGO). The default build embeds the SQLite library through the pinned Go driver; no Go runtime is needed to run the resulting executable. From the application root:

   ```sh
   mkdir -p storage/current
   cd vendor/gatienorti/current/collector
   go test ./...
   go build -trimpath -o current-collector .
   cd ../../../..
   cp vendor/gatienorti/current/collector/current-collector storage/current/current-collector
   chmod 0755 storage/current/current-collector
   ```

   Run `php artisan current:install` before starting a newly built collector so its required history migrations are present. If the async identity migration is missing, startup fails without consuming pending events.

   Build from the installed, trusted package source; do not copy binaries across CPU architectures or incompatible operating systems. The release workflow builds statically with musl, including SQLite, so its Linux executables do not require Go, a compiler, or a matching glibc version at runtime.

3. Configure the application:

   ```dotenv
   CURRENT_TELEMETRY_DRIVER=redis
   CURRENT_TELEMETRY_REDIS_CONNECTION=default
   CURRENT_TELEMETRY_NAMESPACE=my_application
   ```

   The selected `database.redis` connection must use explicit `host`, `port`, `database` and optional `username`, `password`, `scheme=tls` fields. URL-style connections are rejected. TLS verifies the peer and hostname using the system trust store, or approved custom CA/client-certificate options (`context.stream` for phpredis, `ssl` for Predis). Certificate paths must be readable by PHP and the matching collector; a collector advertising Redis TLS support is required. Disabling verification and unsupported TLS options are rejected. Isolated authentication, custom CA, mutual TLS, and IPv6 checks are recorded in the [compatibility evidence](COMPATIBILITY.md); verify your own deployment credentials and certificate names.

   Choose a unique namespace per application/database. The application Redis prefix also contributes to isolation. A dedicated Redis connection with short timeouts is created; Current does not change Laravel's existing Redis connection. The transport's connect/read timeout is 200 ms. The collector has separate one-second Redis timeouts.

4. Refresh cached configuration if used, then restart long-lived producers and workers so they use the selected recorder. Run:

   ```sh
   php artisan current:collect
   ```

   Keep this command running under your service manager, with automatic restart and process-group termination enabled. For Supervisor, set `stopasgroup=true` and `killasgroup=true`; choose a shutdown grace longer than a database batch. Run the daemon outside `APP_ENV=testing`: Laravel disables command signal traps in that environment. With a compatible collector on Linux and PCNTL, the CLI replaces itself with Go at the same PID. Go obtains configuration from a short-lived PHP child over a private pipe and handles TERM/INT directly. Compatible binaries without bootstrap support, programmatic calls and explicit `--env` overrides retain the PHP stdin launcher; in normal runtime environments that wrapper forwards TERM/INT and Go notices a departed launcher. It processes at most 100 events per SQL transaction and polls idle queues at 100 ms intervals. Credentials pass through private pipes, not command-line arguments or temporary configuration files. The internal `current:collector-config` command emits sensitive configuration only for this CLI handoff; it is not an HTTP endpoint. Bootstrap output is bounded to 64 KiB and startup to 60 seconds; child errors are sanitized.

5. Check health:

   ```sh
   php artisan current:telemetry-status
   ```

   This emits a credential-free JSON summary and returns nonzero when attention is needed. Job list/dashboard pages also show warnings for a missing collector, old backlog, SQL failure or observation loss. Healthy async collection adds no warning banner. The existing recorder diagnostic remains visible if publication fails.

## Failure behavior

- **Collector interruption:** pending deliveries replay on restart. A process killed after SQL commit but before acknowledgement can replay the batch; event receipts and snapshot revisions prevent duplicate rows and state regression. Snapshot revisions expire with job retention plus one day; deduplication receipts expire one day after event creation, covering the maximum permitted event age.
- **Second collector:** a local file lock (SQLite) or server-side named lock (MySQL/MariaDB), plus a renewable Redis lease, reject competing collectors even across different Redis namespaces targeting the same database. After an ungraceful kill, allow the 30-second Redis ownership lease to expire before restart. The service manager should retry with backoff. Never manually delete ownership while another collector is alive.
- **SQL unavailable/locked:** the long-running collector reports the error and retries accepted pending events. `--once` exits nonzero with the events still pending. Job execution does not wait for SQL persistence. A lost MySQL/MariaDB connection makes the collector exit; the service manager must restart it to acquire a new database ownership lock. It never reconnects silently without that lock.
- **Redis unavailable, buffer full, oversized event:** the recorder does not throw into the job. It records a bounded diagnostic and opens a 30-second circuit; observations during that period may be missing. It does **not** fall back to synchronous SQL automatically.
- **Expired/invalid events:** the collector discards them without storing their payload and increments health counters, so one invalid event does not block valid work indefinitely.
- **Redis server loss:** acceptance is not a guarantee against Redis data loss. Configure Redis persistence/availability for your requirements. Complete Redis state loss can also remove health counters; Current cannot reconstruct observations it never received. Missing history is not proof of success.

Defaults are 2,000 queued events, 128 KiB per event and a one-hour maximum event age. These bounds are configurable under `current.telemetry`. If no publisher refreshes the stream, it expires after twice the maximum event age, so abandoned buffers are bounded. Health counters can reveal unaccounted accepted events while the health hash survives; they cannot recover lost data. Rejected/expired/invalid counters are cumulative for the namespace and warnings deliberately remain visible after loss. They count known losses; events suppressed during an open recorder circuit are not individually counted.

Keep the existing `current:prune` schedule. It removes expired history, async receipts, versions and identity aliases in bounded batches; the collector also prunes its bookkeeping periodically. Producer identity-cache entries expire within twice the configured maximum event age. A cache miss creates a provisional ID in Redis without reading history SQL. The collector resolves it to any retained matching dispatch and preserves that history's expiry. Durable aliases also resolve webhook/broadcast links written after the last job event; expiry tombstones prevent an old identity from recreating expired history.

The async job recorder performs no history SQL reads or writes. Isolated MySQL and MariaDB network-pause checks verify jobs can run while history SQL is unavailable and their buffered observations drain after recovery. This does not remove database dependencies from application code, Laravel's failed-job/batch repositories, the separate webhook/broadcast recorders, or the isolated native runtime sampler. Buffer bounds, event age and Redis availability still apply.

## Upgrading the async protocol

Protocol 2 requires matching package migrations and a collector advertising `identity_protocol: 2` and `maintenance_protocol: 1`. The installer and `current:collect` reject older binaries. New events use `events:v2` and a separate identity-cache key; an already-running old collector cannot consume those events. Health includes both streams and reports an old collector as unavailable for the new protocol.

Pause producers and let in-flight jobs finish. Drain the old collector, then stop it. Upgrade the package, run `php artisan current:install`, build/install its matching collector, and start the collector before restarting all workers and resuming producers. The new collector drains any remaining legacy backlog before processing v2 events, preserving retained IDs across the upgrade. Do not run rolling mixed-version producers or toggle recorder mode inside a live worker; quiescing producers is required for identity consistency. Never delete pending events or ownership keys to bypass an upgrade problem.

## Switching back to synchronous recording

Do not change recorder mode inside live workers. Pause new work/deployments, let current jobs finish, and drain the async backlog with the existing collector. Verify `pending=0`, no storage error and acceptable health. Stop the collector, change `CURRENT_TELEMETRY_DRIVER=sync`, refresh configuration and restart Horizon workers. Resume work only after those workers have restarted. `php artisan current:collect --once` drains currently available events and exits; it is useful while producers are paused, not a substitute for continuously supervising the collector.

If Redis is unavailable and draining is impossible, explicitly accept the history gap before switching. Do not delete a backlog as a way of making health green. Rolling mixed-mode deployments and live configuration toggles are not supported.

## Evidence and limits

Package integration tests cover real Laravel retry history, bounded admission and publication outage, expiry/invalid events, database-lock recovery, competing collectors, manual Horizon raw-payload retries, retained/expired cycle identities, pruning and the existing UI. Go tests cover rollback, post-commit replay, out-of-order snapshots and invalid relationships. Server-database checks additionally cover Unicode, whole-batch rollback, named-lock ownership across namespaces, lost SQL sessions and non-InnoDB rejection. Local MySQL 8.4.10 and MariaDB 11.4.13 each passed 13 tests / 124 assertions; MariaDB also passed through Laravel's mysql driver. ADAPT tests exercise its actual notification job and normal provider stack, with external delivery mocked.

The earlier ADAPT benchmark evaluated an experimental adapter, not this hardened release path. Its throughput/memory results are not a performance guarantee for this implementation, which adds ownership, validation, health and retention work. No new performance benchmark is implied by this change. Hosted CI has passed all six PHP/Laravel profiles, three TLS database configurations and both Linux binary architectures. The checks include shorter repeated recovery runs; longer production-scale validation remains separate. Static release builds run the Go projector tests on each target architecture before producing the executable. Synchronous mode remains the default.

## Reproducing server-database integration checks

Use disposable servers only. The test account needs permission to create/drop databases, create triggers and kill its collector sessions. Normal production collection only needs read/write access to Current tables, metadata inspection and named-lock functions; it does not create databases or triggers. Build the collector first, then run from a development checkout with PHP's `pdo_mysql` extension:

```sh
CURRENT_ASYNC_DB_HOST=127.0.0.1 \
CURRENT_ASYNC_DB_DRIVER=mysql \
CURRENT_ASYNC_DB_PASSWORD=your_disposable_test_password \
CURRENT_REQUIRE_COLLECTOR_TESTS=1 \
vendor/bin/phpunit -c phpunit.xml --filter AsyncTelemetryTest
```

Use `CURRENT_ASYNC_DB_DRIVER=mariadb` for the native Laravel MariaDB connection (or `mysql` for its compatible driver). Create an empty `current_async_test` database first; each test creates and removes a separate randomly named database. Redis uses the existing test harness's unique namespaces. The CI workflow includes MySQL 8.4 and MariaDB 11.4 jobs. These tests verify functional recovery, not production throughput, cluster/failover behavior or a guarantee against Redis data loss.

SQL driver and ownership references: [Go MySQL driver](https://github.com/go-sql-driver/mysql) and [MySQL named-lock semantics](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html).

## Supervision example

Run the collector as the same application user, alongside Horizon. For Supervisor (adjust paths/user):

```ini
[program:current-collector]
command=php /var/www/app/artisan current:collect
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
startsecs=35
startretries=10
stopasgroup=true
killasgroup=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/current-collector.log
```

The 35-second startup window lets a restarted collector encounter an expired ownership lease before exhausting restart attempts. The service remains responsible for restarting an exited process. Configure log rotation and use `current:telemetry-status` for health checks; a running process alone is not proof that its backlog is caught up. Stop this service before replacing its binary. Never remove the ownership key to bypass a live collector.

### Validated transport settings

The PHP transport keeps one validated settings snapshot per Transport instance. Each access compares the relevant SQL/Redis/telemetry configuration values; a change invalidates the snapshot and cached Redis client. Explicit disconnect also clears both. Publications use one consistent settings/client selection, so runtime namespace changes cannot keep sending through the previous cached prefix. Application code holding an already returned Redis client should not reuse it after changing transport configuration.

SQL PDO TLS options deliberately bypass the validation fast path because certificate readability is a filesystem input. Certificate and verification checks still run on each settings access. This optimization does not alter stream envelopes, queue limits, step boundaries, redaction, acknowledgement or collector durability.


### Optional Redis client and custom TLS

Beta.3 supports standalone Predis 2.3/3.x as well as phpredis. Install `predis/predis` yourself and select Laravel's `database.redis.client=predis`; it remains an optional dependency. Native direct/delayed/bulk acknowledgement checks distinguish complete replies from caller-owned MULTI/pipelines and partial/ambiguous replies.

A matching collector supports private CAs, client certificate/key and verified peer names from the selected Laravel Redis connection. phpredis uses `context.stream`; Predis uses `ssl`. Supported settings include `cafile`, `local_cert`, `local_pk` and `peer_name`; certificate/key are paired and paths must be readable by PHP and Go. TLS 1.2+, peer verification and hostname verification remain required; unsupported/insecure options are rejected. The private collector config normalizes these into `redis_tls{ca,cert,key,server_name}`. CustomTLS requires binary `redis_tls:1` and fresh owner-bound heartbeat `redis_tls_protocol:1`; older collectors are rejected/unconfirmed rather than silently ignoring settings. Monitored-tag retention additionally requires `monitored_retention:1`. Upgrade matching binaries before enabling these features.
