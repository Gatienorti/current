# Optional native engine — experimental

Current can now boot without Horizon and run native Laravel Redis workers under its Linux Go supervisor. Horizon remains the default backend. This work is not yet a published stable Horizon replacement. Synchronous or optional async telemetry can be selected independently.

The additional notification routes, full-batch retry passes, watching, orphan cleanup, balancing policy and tag-specific retention described below are development changes after beta.2. They require a version containing migrations 17–19 and the matching supervisor binary; the published beta.2 tag is unchanged.

## Migration preview and setup checks

These commands are development additions after beta.2. In an application with Horizon installed, preview the currently loaded environment:

```sh
php artisan current:migrate-horizon
php artisan current:migrate-horizon --environment=production --json
```

The preview reads the installed Horizon provisioning contract, chooses its first matching environment pattern, and preserves its recursive default/override behavior (including queue/backoff lists). `--environment` selects from the configuration already loaded; it does not load another dotenv file. Queue order, worker limits, balancing strategy and supported worker options are translated. Canonical integer environment values are converted without overflow; unsupported/ambiguous options, invalid native constraints, excessive budgets and incompatible queue drivers block the proposal instead of being silently dropped. Disabled supervisors are identified and omitted. Exit0 means a valid translation, not a completed migration; errors exit1. `--json` emits the report without console decoration. No configuration is written, Redis is not contacted, and no workers are started or stopped.

Horizon internally raises a balanced supervisor's effective maximum to at least the number of process pools, even when the configured maximum is smaller. The preview uses that same effective maximum and emits a `horizon_queue_floor` warning. It does not rewrite Horizon configuration or relax native group/instance limits. If configured per-queue minima still cannot fit, the preview remains blocked.

Review the generated native block and queue-driver changes. Replace the proposed `pools`, `defaults` and `environments` entries rather than recursively combining them with older native pools. Keep unrelated Current settings and merge `queue_connections` with existing approved capture connections. Paths reflect the runtime generating the preview; rerun/review them in the worker deployment. Assign a unique instance and private state path. Initial allocation is shown explicitly; transient balancing behavior is not promised identical. Retention, monitored-tag behavior, silencing, wait alerts and notification routes need their own reviewed Current settings.

The command prints cutover and rollback steps. Keep producers quiescent while draining old work, delayed entries and reservations; stop Horizon through its process manager and prevent it restarting before native workers consume those queues. Do not mix the engines during cutover. Legacy Horizon payloads do not acquire native dispatch identities retroactively. Preserve the original configuration for a drain-and-stop rollback. The command does not certify that your queues are drained.

After applying the reviewed configuration and running the installer separately, inspect the actual worker runtime/user:

```sh
php artisan current:doctor
php artisan current:doctor --json
```

Checks cover Linux/extensions, native pool validation, capture connection names, standalone phpredis PING with bounded private connections, executable ELF architecture, existing private state-directory permissions, access-gate registration, required history tables/core identity columns, failed-provider limits and async collector health when selected. Exit1 means a failed read check; exit0 means those checks passed, with warnings still requiring review. An absent state directory may be reported as a warning when its parent is writable; it is not created by the command. Missing SQLite history is not created or migrated. Configured binaries are inspected without executing them.

These checks do not exercise SQL write permissions, Redis mutation ACLs, a particular user's web authorization, process-manager configuration or release checksum/protocol matching. Database connections use application driver settings/timeouts; file-backed SQLite connections can perform their normal connection initialization. Use the matching trusted release/checksums and `current:collector-install` for collector verification. No jobs, test records, migrations, service controls or configuration edits are issued. Async readiness requires a healthy running collector, so run that check again after starting it separately. Horizon remains the default backend until the operator explicitly switches.

## Installation

Use a dedicated development/test application first. Install Current, explicitly select native execution, and define access:

```dotenv
CURRENT_EXECUTION_BACKEND=native
CURRENT_NATIVE_STATE_DIRECTORY=/srv/app/storage/current/supervisor
CURRENT_NATIVE_INSTANCE=app-worker-a
CURRENT_SUPERVISOR_BINARY=/srv/app/storage/current/current-supervisor
```

```php
Gate::define('viewCurrent', fn ($user) => $user->is_admin);
Gate::define('operateCurrent', fn ($user) => $user->is_admin);
```

Apply your application's actual authorization policy. There is no implicit local-environment bypass. `viewCurrent` permits inspection; `operateCurrent` additionally permits native retry and enabled dashboard controls. POSTs retain CSRF protection.

In the selected `config/queue.php` connection, set `driver` to `current-redis`, retain its Redis connection/queue settings, and ensure `retry_after` exceeds the worker timeout. Do not replace an existing application's worker configuration implicitly. If Horizon is installed, disable its worker service before moving that application's queues to native execution; do not run competing engines accidentally. The clean native installation omits Horizon entirely. Stopping Horizon does not disable its auto-discovered provider. To keep using Horizon, retain its ordinary `redis` driver and `CURRENT_EXECUTION_BACKEND=horizon`.

Drain the old engine's work before a backend cutover. Older queued payloads without Current's native dispatch identity can still execute through Laravel, but they do not retroactively acquire the exact identity required by Current's native retry/maintenance controls. Keep Laravel's normal CLI tools available for retained legacy failures. Existing history remains inspectable; Current does not guess retry lineage during a backend switch.

Run `php artisan current:install` for Current's dedicated history schema. Configure Laravel's normal failed-job and batch storage separately. For SQLite batch storage, keep it separate from history and dispatch jobs after the batch transaction commits (`afterCommit()` or the connection's `after_commit` setting). Concurrent batch-counter updates are verified on MySQL/MariaDB; a shared SQLite application/history file can encounter writer-lock races. Native retries require Laravel's retained executable failure payload; Current's redacted display snapshots are never executed.

Download the supervisor for your Linux architecture from the [matching release](https://github.com/Gatienorti/current/releases), or build it from `supervisor/` on Linux. Verify its accompanying SHA-256 checksum, make it executable, and install it at `current.native.binary`. Native binaries are available from beta.2; Composer does not download or start them automatically. Configure named pools in `config/current.php`:

```php
'native' => [
    'state_directory' => storage_path('current/supervisor'),
    'instance' => env('CURRENT_NATIVE_INSTANCE', 'app-worker-a'),
    'binary' => storage_path('current/current-supervisor'),
    'controls' => false, // Explicitly enable dashboard pause/resume/restart.
    'grace_seconds' => 90,
    'worker_limit' => 32, // Shared live-worker budget for this instance.
    'pools' => [
        'default' => [
            'connection' => 'redis',
            'queues' => ['default'],
            'workers' => 2,
            'min_workers' => 1,
            'max_workers' => 10,
            'timeout' => 60, // Must be below retry_after AND grace_seconds.
            'memory' => 128,
            'tries' => 3,
            'balance' => 'fixed', // Or size, time, simple, auto; see semantics below.
            'jobs_per_worker' => 10, // Size strategy.
            'target_clear_seconds' => 60, // Time strategy.
            'balance_shift' => 1,
            'balance_cooldown' => 5,
        ],
    ],
    'silenced' => [],
    'silenced_tags' => [],
],
```

Other bounded worker options: `sleep`, `backoff` (integer seconds or a nonempty list such as `[1, 5, 30]`), `max_jobs`, `max_time`, `rest`, boolean `force` (default false), and `nice` (-20 to 19, default 0). Nonzero niceness uses the system `/usr/bin/nice` or `/bin/nice` to adjust only the child worker priority, matching Horizon’s relative adjustment; negative adjustments require the appropriate OS privileges. Laravel repeats the final backoff value for later retries. `tries=0` permits unlimited attempts unless the job defines its own limit, retry deadline or exception limit. Job-level retry settings retain Laravel precedence. Workers use their pool name for Laravel worker naming. Maintenance mode pauses consumption unless `force=true` is explicitly selected. Queue names are limited to 100 characters to match retained history and filters. Queue lists are ordered, retaining Laravel's priority behavior; a pool's workers are shared across that list. Separate pools provide independent allocation. Unknown options fail validation. `block_for` must be null or a positive integer shorter than `grace_seconds`; indefinite, string-valued or excessive blocking is rejected because it conflicts with predictable graceful handling. An explicit `current:supervise --env=staging` is forwarded to every worker.

Worker `timeout` accepts integer seconds from 0 through 86,400. Zero preserves Laravel's disabled worker alarm; it does not disable finite shutdown grace or make Redis reservations indefinite. `grace_seconds` stays finite (1–86,430), defaults to90, and must exceed a positive pool timeout. Queue `retry_after` must exceed the pool timeout; review job-level timeout overrides as well: grace and the Redis reservation must cover the longest applicable job deadline, not only the pool setting. The preview derives grace from pool deadlines and configured reservation windows up to the finite supported cap; it does not inspect every dynamic job definition. The process manager/container stop deadline must exceed the configured grace. `current:terminate --wait` accepts0–86,430 seconds; its wait only observes shutdown and does not shorten the running supervisor's grace. A matching rebuilt supervisor is required for grace periods above the older300-second limit. Tests run short busy jobs under long configured deadlines and exercise bounded forced shutdown; they do not simulate a24-hour job.

### Environment-specific pools

Instead of `native.pools`, use named `native.defaults` plus `native.environments`:

```php
'defaults' => [
    'imports' => ['connection' => 'redis', 'queues' => ['imports'], 'workers' => 1,
        'max_workers' => 10, 'timeout' => 60],
    'mail' => ['connection' => 'redis', 'queues' => ['mail'], 'timeout' => 60],
],
'environments' => [
    'production' => ['imports' => ['workers' => 5, 'balance' => 'size']],
    'staging*' => ['imports' => ['workers' => 2], 'mail' => false],
    '*' => ['imports' => ['workers' => 1]],
],
```

The first matching environment wins; put the `*` fallback last. All named defaults are included unless explicitly disabled with `false`. Overrides replace whole queue/backoff lists, rather than merging list positions. `pools` and nonempty `environments` cannot both be configured. An unmatched environment or a configuration with no enabled pools fails validation before launching. The queue architecture page uses the same resolved configuration.

```sh
php artisan current:supervise --check
php artisan current:supervise
php artisan current:supervise --status
php artisan current:supervise --control=pause --pool=default
php artisan current:supervise --control=resume --pool=default
php artisan current:supervise --control=restart --pool=default
php artisan current:supervise --control=stop
```

Use a process manager with a stop timeout longer than Current's grace. PHP and Go must have the same OS user and private local state directory. Each concurrent instance needs a unique name (`--instance=app-worker-b` also overrides the configured name). Controls target the configured local instance. Shared process inventory can also show other hosts using the same Redis prefix and registry namespace; it does not provide remote controls or distributed execution fencing.

## Operational semantics

The native dashboard includes processing rate, captured jobs in the past hour, retained failures in the past seven days, status, live processes, maximum reported wait, and queue rankings for captured mean runtime and throughput. These use Current observations: the rate spans the last minute (or the shorter period after a metric reset), and queue rankings use the past hour since the reset. They are not Horizon snapshot values. Stale/incomplete worker inventory and missing workload evidence remain unknown. Queue architecture refreshes its live worker counts while keeping configured worker ranges distinct.

Pause drains the selected pool; resume recreates workers. Restart drains each selected worker before replacing its slot. Scale-down drains surplus slots while keeping other workers; a draining slot retains its capacity until reaped. Grace expiry forcibly terminates unfinished workers, which remain subject to Laravel's reservation/retry behavior. These operations do not guarantee exactly-once external effects.

Size balancing uses ready plus reserved queue counts, min/max limits, a maximum shift and cooldown. Future delayed jobs are excluded until Laravel migrates them. Unknown Redis data or stale supervisor health holds the allocation. PHP performs this observation in the existing launcher; this is not a Go-only Redis scheduler. The optional time strategy estimates ready/reserved work from captured completed/failed attempt runtimes in the past five minutes, using a short-lived Redis snapshot and aims to clear it within `target_clear_seconds`. Busy queues without recent usable samples, invalid timing, or unavailable history hold existing busy allocation; a zero-worker pool can request one bounded probe from confirmed ready or due work. This is an estimate based on Current coverage, not Horizon's proportional per-queue algorithm. For dedicated per-queue workers, use the balanced modes below.

### Dedicated queue balancing

Set `balance` to `simple` or `auto` to give each queue dedicated Laravel workers instead of one shared priority-ordered consumer. In these modes, `workers` is the initial **total**, `min_workers` applies **per queue**, and `max_workers` caps the **whole configured pool**. The initial total must cover every queue's minimum. `simple` distributes the initial total evenly, assigning any remainder deterministically. `auto` redistributes the pool's budget using `auto_scale_strategy`:

- `size`: proportional ready job counts.
- `time`: proportional ready count × recent captured runtime; missing runtime evidence holds the group's allocation.
- `log`: proportional `log(1 + ready jobs)`, reducing how much a very large queue dominates.

Empty queues retain their minimum. Reserved and future delayed jobs do not add demand in these proportional modes. `balance_policy` defaults to `horizon`: known queue weights produce proportional recommendations rounded up, with the same per-queue floor and shared-cap checks used for growth. Recommendations can sum above the available budget; confirmed reductions and live capacity always constrain actual growth. Size/time recommendation tests compare directly with the installed Horizon implementation. Set `balance_policy` to `bounded` to retain beta.2's deterministic proportional rounding. Unknown queue evidence holds allocation. Unknown runtime keeps existing busy allocation and remains unknown in metrics; confirmed ready/due work can wake one zero-floor consumer, and known idle allocations may release capacity through accepted controls. These policies do not promise identical transient process counts across the two engines. Existing `fixed`, `size` and `time` modes preserve their shared ordered queue list and previously documented clearance behavior.

For example, `queues => ['mail', 'imports']`, `workers => 2`, `min_workers => 1`, `max_workers => 10`, `balance => 'auto'`, `auto_scale_strategy => 'size'` starts one worker per queue and shares up to ten. At most 256 expanded worker pools are supported. `current:supervise --check` shows the generated queue pool names. Pause/resume/restart accepts either one generated name or the configured parent pool name; stopping remains an instance operation.

Go enforces both the parent-pool and instance limits, including draining processes. A paused queue reserves its allocation. Lost control replies stop the current rebalance until fresh health is available; rejected reductions do not free capacity.

`worker_limit` (1–4096, default 256) caps the whole instance independently of pool maxima. Autoscaling requests reductions before growth and only reuses confirmed allocation. Go retains capacity for draining workers until they exit, so reallocating across pools cannot temporarily exceed the live-worker cap. Fixed/paused allocations still reserve their configured share. Budgets are local to one instance, not cluster-wide.

Native queue architecture uses the same validated configuration as the launcher. Native metric lists summarize captured completed attempts in the past hour, separated by job class and connection/queue. Each links to retained five-minute history. Run `current:install` after updating, then keep Laravel's scheduler running: `current:snapshot` is registered every five minutes for native mode. It aggregates captured completed/terminal-failed attempts outside worker execution; it does not measure unobserved throughput. Missing snapshots remain gaps. Zero means no captured finishes, and invalid/missing durations are excluded from runtime averages. The latest three closed windows are replaced idempotently to include recent late telemetry; data arriving later than fifteen minutes is not automatically corrected. Snapshot retention defaults to 24 hours (`native.metrics.retention_hours`, 1–168), with hourly pruning. A window exceeding 1,000 job classes or 1,024 queue groups fails without publishing partial results. Disable scheduled aggregation with `native.metrics.enabled=false`. Queue history also shows scheduled point-in-time wait estimates, ready counts and confirmed consuming capacity. These samples use the actual observation time, include the current bucket, and are never backfilled into corrected past windows. Unknown inventory, queue load or runtime remains unknown; priority queues can share the same capacity. Reset and retention apply to both charts.

Batch listing reads Laravel's retained repository. Explicit `tags()` values and automatic Eloquent model tags are discovered from dispatch objects, including queued listener, mail, notification and broadcast wrappers. Explicit nonempty tags take precedence over model discovery; listeners can receive their event in `tags($event)`. Discovery is bounded to 100 properties/models/tag candidates and skips uninitialized/static properties and models without a key. All discovered tags still pass through Current's tag-prefix allowlist and retained-tag limits; class/tag silencing uses those approved tags at enqueue. A tag callback failure cannot break dispatch and leaves classification unknown.

Batch details show retained captured members, counters and progress. Authorized operators can retry selected shown failures or start a full-batch retry pass. A full pass reads Laravel's retained failed IDs, including failures absent from Current history, and processes at most 50 per request. With JavaScript, one initiating action continues sequential chunks; Pause finishes the current request before stopping. Without JavaScript, the page provides explicit continuation. Unknown outcomes, malformed responses and network failures stop automatic work and preserve an explicit review/resume link. There is no automatic resend.

Each eligible failure must have a matching native dispatch identity, UUID, queue connection and bounded command whose batch membership agrees with the retained Laravel batch. For encrypted jobs, the explicit retry action authenticates/decrypts using the application encrypter, then inspects the serialized data without instantiating job objects; decrypted commands are never persisted or logged. Expired executable payloads, invalid ciphertext/keys, malformed/oversized commands and unverified membership remain unavailable. The pass expires after 24 hours and excludes dispatches created at or after its starting millisecond, preventing a replay from retrying its own new failures. Batch and individual retries share identity claims that outlive captured history. Migration17 backfills retained legacy claims before pruning. Built-in database UUID/numeric, locked File and DynamoDB providers have exact-deletion adapters. Custom providers can implement `Current\Contracts\FailedJobMaintenance` for atomic deletion and bounded snapshot pagination; unsupported adapters leave cleanup unconfirmed rather than deleting a possibly replaced payload. Retrying a cancelled batch does not uncancel it. Batch cancellation is not provided.

Retries require `operateCurrent`, matching connection/queue and the exact retained dispatch identity. Laravel prepares the executable payload and resets its retry fields. Current records the new dispatch separately even when Laravel reuses its UUID. A durable request claim prevents automatic duplicate POST retries; an ambiguous outcome remains unconfirmed.

### Shared supervisor inventory

Native launchers publish process observations every two seconds to a bounded Redis registry. Defaults:

```php
'registry' => [
    'enabled' => true,
    'redis_connection' => 'default',
    'namespace' => 'current',
],
```

All participating web/worker hosts must use the same application Redis prefix, namespace and standalone phpredis or Predis 2.3/3.x connection. Use separate prefixes/namespaces for unrelated applications/environments. The registry uses a private, nonpersistent connection with 500 ms connection/read timeouts and no automatic retries; it does not alter the application's Redis connection settings. TLS/auth configuration is inherited from the selected connection. Registry failure does not stop workers.

Host, instance and process generation distinguish records; control ownership tokens and worker commands are not published. Missing heartbeats become stale after six seconds, with live process counts shown as unknown. Records expire after sixty seconds; at most 256 recent instances are listed. Graceful shutdown publishes a final stopped state. The dashboard's existing access gate protects this inventory, and remote records have no control buttons. This is visibility, not a cluster-wide allocation budget, leader election or proof of job completion.

Normal worker exits and Laravel's memory-limit exit code retain a fixed short restart delay. Crashes retain increasing backoff and the restart budget. Process-manager restart supervision is still required.

## Compatibility and evidence

Standalone phpredis and optional Predis 2.3/3.x support native enqueue confirmation. Bulk observations flush only after a complete successful EXEC response. Serialization failures, partial Redis errors, ambiguous replies and telemetry-buffer overflow do not fabricate enqueues. Caller-owned pipelines/transactions are never committed by Current and do not fabricate enqueue confirmation; subsequent workers may establish start-only history. Redis Cluster confirmation remains unsupported/unvalidated. Workers can still establish start-only history; that must not be advertised as complete enqueue coverage.

The optional collector retains its own documented standalone Redis/storage constraints. This native engine does not expand collector topology support. Native execution remains experimental while broader production usage and sustained-recovery evidence accumulate. No universal speed or memory improvement is promised.

See [compatibility](COMPATIBILITY.md) for tested Laravel/PHP profiles and current limits, and inspect the hosted checks for your release revision. Local tests cover a lost bulk-EXEC acknowledgement and a rolling handover between two container hostnames; they do not establish all Redis failure modes or production multi-host reliability.

Async protocol 2 removes history SQL reads from the job recorder's identity-cache-miss path. Isolated MySQL/MariaDB network-pause checks verify execution and subsequent history recovery, with matching collector/migrations and the documented stopped-producer upgrade. Time-based balancing and workload requests read only bounded Redis runtime observations; SQL aggregation runs in an isolated, time-limited sampler. Application SQL, batch/failed-job storage and the separate webhook/broadcast recorders retain their own database dependencies.


### Workload and wait notifications

The native dashboard reads ready Redis counts using the queue routing reported at supervisor startup. Identical queue groups across instances share one row; the local instance is deduplicated against its registry record. Clearance estimates use recent captured runtime divided by confirmed consuming workers. Stale inventory, unavailable runtime data, and queues overlapping different routing groups remain unknown. These are estimates, not measured oldest-job latency. A private Redis connection bounds connect/read waits and a workload observation pass has a time budget; observation does not alter host queue connections.

Optional thresholds emit `Current\Events\QueueWaitExceeded` through Laravel's event dispatcher:

```php
// Inside current.native:
'waits' => ['redis:default' => 60],
'wait_cooldown' => 300,
```

Register an application listener for that event or explicitly enable the routes below. Nothing is sent by default. The event includes the connection, ordered queue list, estimated seconds, configured threshold and observation time. A shared Redis claim suppresses repeat events during the cooldown (60–86400 seconds). Unknown/partial observations never trigger an alert; a failed or ambiguous listener is not automatically retried. This is a notification hook, not guaranteed notification delivery.

Optional built-in routing is available after beta.2:

```php
// Inside current.native:
'notifications' => [
    'enabled' => true,
    'mail' => 'operations@example.com', // Or at most ten addresses.
    'slack' => null, // Configured HTTPS webhook or Slack channel ID.
    'slack_channel' => null, // Optional channel for legacy webhook delivery.
    'sms' => null, // Approved Vonage recipient number.
],
```

Use your application's existing mail configuration. Slack requires `laravel/slack-notification-channel:^3.0`; SMS requires `laravel/vonage-notification-channel:^3.0` and the host's credentials. These packages are optional; Current installs none automatically. Routing stays disabled until explicitly enabled. Sends occur synchronously from the separate scheduled monitor rather than queueing notifications into congested work. A failed route does not suppress other routes or custom event listeners, and transport errors never log recipient addresses, webhook URLs or exception messages. The existing cooldown applies even when delivery fails; provider delivery is not guaranteed. Avoid configuring a custom listener and a built-in route to send the same alert twice.

Laravel's scheduler invokes `current:monitor` every minute only when native thresholds are configured. For a shared ordered queue group, the key is the connection followed by the comma-separated ordered queues, such as `redis:high,default`; the threshold applies to that group's estimate. All scheduler/web hosts must resolve reported queue connection names to the same Redis databases/prefixes as their workers.


### Runtime observations

Each native launcher refreshes runtime estimates approximately every ten seconds through an isolated sampler. The sampler boots PHP with history configuration over a private stdin pipe, queries the previous five minutes of completed/failed attempt durations, and publishes only queue names, aggregate durations and observation times. SQL credentials never appear in command arguments or generated configuration files.

The Go supervisor binary bounds each sample to four seconds, limits input/output to 256 KiB, and kills/reaps the sampling PHP process group on timeout, cancellation or launcher loss. At most one sample per launcher runs at once. Rebuild/install the matching supervisor binary when upgrading; older binaries leave runtime observations unavailable rather than substituting zero.

Runtime observations expire after thirty seconds. Missing, malformed, stale or unavailable data holds time-based allocation and leaves workload/wait estimates unknown. Empty queues still have zero ready work. Autoscaling, dashboard workload and wait alerts never issue the history SQL query themselves. Size/log balancing continues to use queue counts. Sampling adds a transient PHP process; no speed or memory improvement is inferred from this isolation.

### Deployment termination

Use `php artisan current:terminate` to request graceful shutdown of the configured local instance. `--instance=name` targets another instance in the same application's private state directory; `--all` targets all its local instance records. `--wait=30` waits up to thirty seconds for the targeted generation to report stopped with no observed running workers. The default only confirms command acceptance. A timeout, missing/corrupt record, rejected owner or replaced generation remains unconfirmed and exits unsuccessfully.

This command reads existing ownership and the local control socket without validating the new worker plan or requiring a newly deployed binary to exist. It therefore remains usable when a deployment contains invalid pool configuration. Run it on each deployment host and let your process manager start the new launcher. It does not signal unrelated process IDs or control remote hosts. Each application must have its own private state directory. Graceful termination still uses the running supervisor's configured grace period.

### Queue and failed-history maintenance

These commands require the native backend and use Laravel's normal production `--force` confirmation. They are CLI operations; no destructive HTTP endpoints are added.

- `php artisan current:clear redis --queue=emails` atomically removes waiting and delayed entries for one explicitly observed native connection/queue. Reservations are retained. Captured job/attempt evidence remains; removed entries display **Cleared** until a later observed attempt establishes another outcome. A new release after the clear is pending again. A waiting copy matching an active reservation keeps its execution history unchanged.
- Each clear prints an operation ID before submission and atomically detaches a point-in-time snapshot. It reconciles bounded chunks without a total entry ceiling; later dispatches stay on the live queue. Use `--max-chunks=N` to pause and `--resume=ID` with the same scope to continue after interruption. Progress refreshes the staging/receipt TTL (24 hours); expired/missing receipts never clear new work. Uninspectable payloads can be removed while historical identity remains unknown. Add `--including-reserved` for Horizon-equivalent reservation removal: this does not terminate an already running handler or claim its completion. External `queue:clear` does not annotate Current history.
- `php artisan current:forget --all` starts a bounded resumable failed-history/payload pass. `--max-chunks=N` pauses; `--all --resume=ID` continues the retained snapshot. New failures are excluded where the provider can establish that boundary. Unconfirmed/blocked entries remain reported for review.
- `php artisan current:forget LARAVEL_FAILED_ID --failed-id` addresses a retained Laravel failure even without captured Current history.
- `php artisan current:forget CURRENT_HISTORY_ID` removes one terminal native failure's captured job, attempts, steps and tags, plus its exact matching Laravel failed payload. It rejects unfinished attempts and unconfirmed retry claims. `--history-only` retains executable failed storage and supports cleaning an earlier generation after a newer failed payload has replaced it. Application-level retry/forget operations outside Current must be quiesced during removal.
- Failed payload storage and history can be separate databases. If executable payload deletion succeeds but history commit fails, the command reports a partial/unconfirmed result. Inspect both stores, then use `--history-only` to reconcile; it never retries an ambiguous enqueue or deletes a replacement dispatch automatically.

Clear/forget actions retain only a dispatch fingerprint, action time and optional history ID, bounded by the configured maximum history retention plus one day. Collector and synchronous recording honor them, preventing buffered events from recreating forgotten captures. Related webhook/broadcast activity keeps its own retention and becomes unlinked when its job history is removed. Use a matching collector advertising maintenance protocol1; commands refuse an active old/unconfirmed collector before queue changes. Never mix old and new producers/collectors during an upgrade.

`php artisan current:clear-metrics` resets native metric summaries/charts and establishes a cutoff so scheduled snapshots do not rebuild pre-reset data. A reset inside a five-minute window marks that first window partial. Captured jobs, runtime observations and dashboard history remain available; resetting charts does not change worker allocation.

### Batch navigation and retry scope

The native batch list supports cursor navigation beyond the latest fifty records. Laravel database batch storage also supports literal name/ID search; `%`, `_` and `!` are treated as ordinary search characters. Retained batch detail uses the configured Laravel batch repository. Custom repositories can provide ordinary cursor navigation, but database name search is not assumed for them.

Selected retries operate on at most fifty displayed failures per request. Full-batch passes use the separately verified retained executable evidence described above; missing Current history alone never grants retry permission. Application operators can use Laravel's own batch retry tooling outside Current with its normal semantics; quiesce independent retry/forget operations when using Current maintenance. Built-in database UUID payload cleanup uses an atomic, byte-sensitive comparison so a fast worker's replacement failure cannot be deleted by cleanup of the original. Custom failed providers cannot promise this operation and remain unconfirmed. No remote PID purge or hosted notification service is implied.

### Tag retention

All approved captured tags remain searchable without a separate monitored-tag screen. To keep selected native histories longer, configure exact approved tags:

```php
'tag_retention_days' => ['order:important' => 14],
```

Tags must also match `current.tag_prefixes`. The longest matching policy applies, without shortening the ordinary retention, and remains capped by `max_retention_days` (hard maximum 30 days). At most 1,000 exact policies are inspected. The first observation fixes expiration in both recording modes; later events and configuration edits do not slide an existing history's lifetime. This is bounded history retention rather than a second Horizon-style Redis tag index.

### Development watching and orphan cleanup

`php artisan current:listen` runs the native launcher and watches configured `native.watch` files/directories. Bounded PHP polling requires no Node package. Source changes drain the owned launcher before starting a replacement. Generated `bootstrap/cache` files are ignored unless explicitly selected. The watcher is for development; production still uses a process manager. Symlinks, unsupported paths, more than 20,000 entries or scans above 64 MiB fail explicitly rather than silently missing changes.

`php artisan current:purge` inspects the configured local instance; `--instance=name` or `--all` selects other records in this application's private state directory. An active ownership lock is preserved. For abandoned recorded worker slots, the matching Go binary verifies boot ID, process start ticks, UID and a private random birth token, then signals through a held Linux pidfd. Default `--signal=TERM --wait=5` requests termination and waits for exit; explicit `--signal=KILL` is available for a proven unresponsive worker.

Missing/old/corrupt identity, unsupported pidfds, inaccessible process information and surviving workers after the wait remain unconfirmed. No raw-PID fallback, global discovery, remote signaling or arbitrary descendant killing occurs. Install the matching binary and restart workers before relying on proof fields; beta.2 records lack them. Runtime samplers retain their separate bounded timeout and parent-death cleanup. This operation does not guarantee that unrecorded application-created subprocesses are gone.

### Process-manager setup

Run the launcher in its own service or container, with descendant cleanup enabled. For example, with systemd and the default90s grace period:

```ini
[Unit]
Description=Current queue supervisor
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php /srv/app/artisan current:supervise --instance=worker-a
Restart=always
RestartSec=5
KillMode=control-group
TimeoutStopSec=105

[Install]
WantedBy=multi-user.target
```

Replace the user, paths and instance name for your application. Configure native execution and the private writable state/binary paths before rebuilding Laravel's configuration cache. Give each concurrent launcher a distinct instance name. Keep the manager's stop timeout longer than `grace_seconds`; the cgroup cleanup covers all descendants even though workers have separate Unix process groups. Containers need an init/reaper and a container stop timeout longer than the native grace. A process group's cleanup is not a replacement for cgroup/container ownership when the engine itself is forcibly killed. Run an enabled async collector as its own supervised service using `php artisan current:collect`.

The launcher forwards engine output without retaining its full output history. Configure log rotation in your service manager. Healthy worker recycling remains bounded by its short restart delay; repeated crashes exhaust the native restart budget and should be visible through your service-manager failure monitoring. No worker restart mechanism guarantees exactly-once external effects.


### Current development capability additions

After updating, run `current:install` for migrations 18–19 and install matching supervisor/collector binaries. Existing beta.2 binaries do not implement the new supervisor options or collector capabilities. The Horizon backend remains available.

`current:status --all` lists local instances/pools; `--registry` lists shared host inventory; `--json` emits structured status without control ownership tokens. These checks and `current:control pause|resume|restart|stop` use existing records, so invalid replacement worker settings or a missing new binary do not block deployment controls. Use `--all` for local instances or `--instance=NAME`, and `--pool=NAME` for pause/resume/restart. Stop remains an instance operation. The older `current:supervise --status/--control` flags delegate to these commands.

Pools support `worker_name`, `output` (`quiet`, `cli`, or supported Laravel `json`) and `paused=true` startup. The global `native.worker_output` defaults to quiet. Set `min_workers=0` and an explicit positive `max_workers` to permit idle zero-worker pools; size balancing can grow them when ready work appears. Time balancing keeps missing runtime unknown; a zero-worker pool can start one bounded probe from confirmed ready or due work. `current:supervise --environment=NAME` selects provisioning separately from Laravel's application environment; `CURRENT_NATIVE_ENVIRONMENT` supplies a persistent selection.

Budgets remain explicit: up to 4096 live workers per instance, 256 expanded pools and 256 ordered queues per configured pool; default worker budget 256 is unchanged. Configuration/health input bounds scale with these limits. Large-plan validation is not a load benchmark or a claim that a particular machine can run that many workers. `native.memory_limit` defaults to 64 MiB separately for the PHP launcher and Go supervisor's allocated heap/stacks, excluding worker children; excess triggers graceful drain. A process manager must restart the instance. Use worker `memory` for PHP job process recycling.

Monitoring's compact filters stay the default. **Manage monitoring** adds/removes approved tags and links to paged monitored/failed history. `native.monitoring` configures connection,namespace,retention_days(default 7,maximum 30/global cap),max_tags(default 128,configurable 1–10000). Policy changes reach running workers on their next observation. New/finishing matching jobs extend full retained details; raw rejected tags and earlier unobserved history are not backfilled. Async monitoring requires a live matching collector advertising `monitored_retention:1`; old binaries cannot silently claim the longer retention. Stopping monitoring keeps previously established bounded detail retention.

Silencing follows the underlying queued listener, broadcast event, mailable or notification, including configured class names and `Current\Contracts\Silenced` (or Horizon's installed marker). Configured silenced tags are evaluated transiently before capture redaction; only approved tags and the classification are persisted.

`current.domain` / `CURRENT_DOMAIN` can restrict dashboard routes to a chosen application subdomain using Laravel routing. Authorization and middleware still apply.

Aggregate plan limits are checked by PHP before launch as well as by Go: 4 MiB encoded configuration, 2 MiB shared inventory and 1,024 distinct connection/queue routes per instance for bounded runtime sampling. Individual pool/queue ceilings do not promise every maximum can be combined. Split larger reviewed deployments into separate instances. A registry record exceeding its bound remains an explicit incomplete observation; it is not silently omitted from fleet completeness. Draining processes remain visible as processes but do not contribute consuming capacity or finite wait estimates.

Custom failed providers without atomic/paginated capabilities can use `--allow-nonatomic-provider` explicitly for a single ID or a bounded captured-history `--all` pass. This matches Laravel's basic find/forget behavior with a final exact pre-read, but concurrent replacement can still race between those calls; the command warns and never claims atomic deletion. It never loads arbitrary provider `all()` output. Prefer the adapter contract and quiesce external retries/forget operations. Ambiguous calls are retained for review, not automatically resent.

Zero-floor recovery also checks due delayed entries and expired reservations without changing the displayed LLEN ready count. Future delayed entries and unexpired reservations do not wake a pool. One wake consumer remains allocated while due work waits for a slow worker bootstrap; Laravel still performs migration, reservation and execution. These are bounded native wakeup rules, not invented runtime samples. When measured total queue time is zero, busy-queue recommendations follow the pinned Horizon fallback; actual growth still obeys parent/instance budgets, accepted reductions and draining-process capacity.
