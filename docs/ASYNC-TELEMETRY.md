# Opt-in asynchronous job telemetry

Current defaults to synchronous recording. An optional Redis transport and Go collector move **job, attempt and step SQL writes** out of Laravel workers while Horizon continues to execute jobs. Existing Args/Metadata sanitization, source inspection and the Redis acceptance round trip still happen in PHP. Webhook and broadcast recording stay synchronous.

## Initial support boundary

The opt-in implementation targets **Linux, PHP with phpredis and standalone Redis 7**, with one of:

- **SQLite** on a local Linux filesystem.
- **MySQL 8.4 or MariaDB 11.4**, with Current tables using InnoDB and one explicit server connection.

Current uses the database configured by `current.connection`; it does not move existing history or change the application's default database. MySQL/MariaDB credentials are passed to the collector over stdin. The collector uses a pinned SQL connection, UTF-8 (`utf8mb4`), strict writes and a one-second InnoDB lock-wait timeout. It never uses `INSERT IGNORE`, which could hide truncation or other write errors.

SQL URL-style connections, read/write splitting and table prefixes are rejected rather than silently ignored. The collector supports Laravel PDO TLS options `MYSQL_ATTR_SSL_CA`, `MYSQL_ATTR_SSL_CERT`, `MYSQL_ATTR_SSL_KEY` and `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`; other PDO options remain unsupported. TLS verifies the server certificate and hostname, requires TLS 1.2+, supports private CAs and mutual TLS, and never falls back to plaintext. Disabling verification is rejected. Certificate paths must be readable by both the PHP workers and the collector; configure the SQL host to match the certificate. A client certificate and key must be supplied together. PostgreSQL, Redis Cluster/Sentinel, SQLite memory databases, network filesystems and Docker Desktop host-shared database files are outside the validated boundary. The SQLite lock/recovery tests pass on container-local storage; a Docker Desktop bind-mounted fixture did not enforce the expected cross-process lock behavior.

Use one collector per Current database/namespace. The Go supervisor remains an independent experiment: this mode works with Horizon and does not replace its process manager, balancing or retry engine. Current still depends on Horizon.

## Install and start

1. Choose the existing Laravel history connection with `CURRENT_CONNECTION` (for example, a dedicated `current_mysql` connection), then run Current's package migrations with the normal installer. Keep the existing setting if you already have history; changing this setting does not migrate old records:

   ```sh
   php artisan current:install
   ```

2. Install a matching collector binary. The [Collector binaries workflow](https://github.com/Gatienorti/current/actions/workflows/collector-binaries.yml) produces static Linux `amd64` and `arm64` executables plus SHA-256 files, available as workflow artifacts. When a release containing this feature is published, the workflow also attaches them to that release. The existing `v0.1.0-beta.1` predates this feature; do not assume it has these assets.

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

   Build from the installed, trusted package source; do not copy binaries across CPU architectures or incompatible operating systems. The release workflow builds statically with musl, including SQLite, so its Linux executables do not require Go, a compiler, or a matching glibc version at runtime.

3. Configure the application:

   ```dotenv
   CURRENT_TELEMETRY_DRIVER=redis
   CURRENT_TELEMETRY_REDIS_CONNECTION=default
   CURRENT_TELEMETRY_NAMESPACE=my_application
   ```

   The selected `database.redis` connection must use explicit `host`, `port`, `database` and optional `username`, `password`, `scheme=tls` fields. URL-style connections are rejected. Use the normal system trust store for TLS; custom TLS options are not implemented. Authentication/TLS configuration is wired but the local tests use unauthenticated Redis, so those deployment modes require your own connection verification.

   Choose a unique namespace per application/database. The application Redis prefix also contributes to isolation. A dedicated Redis connection with short timeouts is created; Current does not change Laravel's existing Redis connection. The transport's connect/read timeout is 200 ms. The collector has separate one-second Redis timeouts.

4. Refresh cached configuration if used, then restart Horizon workers so they use the selected recorder. Run:

   ```sh
   php artisan current:collect
   ```

   Keep this command running under your service manager, with automatic restart and process-group termination enabled. For Supervisor, set `stopasgroup=true` and `killasgroup=true`; choose a shutdown grace longer than a database batch. The wrapper forwards TERM/INT, and the Go collector also notices a departed launcher. It processes at most 100 events per SQL transaction and polls idle queues at 100 ms intervals. Credentials pass through standard input, not command-line arguments or temporary configuration files.

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

Keep the existing `current:prune` schedule. It removes expired history and async receipts/version records in bounded batches. The collector also prunes expired receipt/version rows periodically. Identity-cache entries expire within twice the configured maximum event age; a job observed after its retained cycle expires receives a fresh cycle ID. On cache misses, a read checks existing SQL history so switching modes can preserve a retained cycle. No telemetry SQL write occurs in the async recorder.

## Switching back to synchronous recording

Do not change recorder mode inside live workers. Pause new work/deployments, let current jobs finish, and drain the async backlog with the existing collector. Verify `pending=0`, no storage error and acceptable health. Stop the collector, change `CURRENT_TELEMETRY_DRIVER=sync`, refresh configuration and restart Horizon workers. Resume work only after those workers have restarted. `php artisan current:collect --once` drains currently available events and exits; it is useful while producers are paused, not a substitute for continuously supervising the collector.

If Redis is unavailable and draining is impossible, explicitly accept the history gap before switching. Do not delete a backlog as a way of making health green. Rolling mixed-mode deployments and live configuration toggles are not supported.

## Evidence and limits

Package integration tests cover real Laravel retry history, bounded admission and publication outage, expiry/invalid events, database-lock recovery, competing collectors, manual Horizon raw-payload retries, retained/expired cycle identities, pruning and the existing UI. Go tests cover rollback, post-commit replay, out-of-order snapshots and invalid relationships. Server-database checks additionally cover Unicode, whole-batch rollback, named-lock ownership across namespaces, lost SQL sessions and non-InnoDB rejection. Local MySQL 8.4.10 and MariaDB 11.4.13 each passed 13 tests / 124 assertions; MariaDB also passed through Laravel's mysql driver. ADAPT tests exercise its actual notification job and normal provider stack, with external delivery mocked.

The earlier ADAPT benchmark evaluated an experimental adapter, not this hardened release path. Its throughput/memory results are not a performance guarantee for this implementation, which adds ownership, validation, health and retention work. No new performance benchmark is implied by this change. Hosted CI has passed all six PHP/Laravel profiles, three TLS database configurations and both Linux binary architectures. The checks include shorter repeated recovery runs; longer local-run evidence is recorded in PLAN.md. Static release builds run the Go projector tests on each target architecture before producing the executable. Synchronous mode remains the default.

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

### Sustained verification

Three corrected ten-minute local runs covered **7,157 jobs, 14,308 attempts and 144,879 accepted observations** across SQLite, MySQL and MariaDB. Each completed 13 collector restarts, including SIGKILL with actual ownership-lease expiry; retained counts matched and every accepted observation was processed. The first trial exposed a graceful shutdown acknowledgement race, which was fixed and regression-tested before these runs. [Machine-readable evidence](verification/async-telemetry-2026-10-26.json) includes scope and reproduction. Run `CURRENT_ASYNC_SOAK_SECONDS=600 vendor/bin/phpunit -c phpunit.xml --filter test_sustained_collection` only against isolated test services, adding the SQL/TLS environment settings for server databases.

These finite runs do not establish behavior at arbitrary production load, during permanent Redis data loss, or across untested database topologies. The synchronous default remains the conservative installation path.
