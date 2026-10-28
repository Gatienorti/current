# Changelog

## Unreleased

- Native runtime sampling uses a small isolated Laravel database reader for standard connections, avoiding a full provider boot. Custom connections retain the guarded bootstrap path. New launcher configurations default to 128 MiB with an environment override and startup/doctor memory diagnostics.

- Add an all-attempt timeline with compressed spacing, per-attempt execution policy provenance, and targeted configuration notices. Apply migration 20 and restart the async collector before upgraded workers.

- Native worker identity capture can retry temporarily unavailable `/proc` evidence within a bounded100ms budget while checking the original spawn pidfd. Wrong tokens, exited processes and missing proof remain ineligible for purge.

- Optional Predis 2.3/3.x native queue and async telemetry support, with complete-acknowledgement handling, bounded observation connections, and verified custom Redis CA/mutual TLS for matching collectors.
- Native monitored-tag controls retain full matching job details with bounded retention; wrapper-aware class/tag silencing recognizes Current and installed Horizon markers.
- Queue history includes point-in-time wait/load/capacity samples without invented backfill.
- Native status, shared inventory and local controls no longer depend on validating replacement worker configuration; paused startup, zero-worker floors, custom worker names/output, provisioning environment selection, dashboard domains and orchestration memory limits are supported.
- Zero-floor time balancing can wake one bounded consumer for confirmed ready or due work without inventing runtime data; measured-zero recommendations follow Horizon’s busy-queue fallback.
- Larger reviewed native budgets support4096 live workers and 256 expanded pools while retaining explicit default limits.
- Queue clearing works through resumable snapshot chunks with an explicit reservation-removal option; bulk failed-job maintenance supports bounded continuation and additional failed-storage adapters.

- Synchronous recorder heartbeats use an atomic upsert, avoiding a first-heartbeat insertion race that could pause subsequent observation without affecting job execution.
- Native worker timeouts now preserve 0 (disabled Laravel alarm) and deadlines through 24 hours, with finite shutdown grace through 86,430 seconds. Watch/deployment commands honor the longer grace. Install the matching rebuilt supervisor.
- Horizon migration previews account for Horizon's effective one-process-pool-per-queue maximum and report that adjustment explicitly; native instance/group caps remain enforced. Unsafe queue reservation deadlines still block migration.
- `current:migrate-horizon` previews a native configuration using installed Horizon environment/default semantics, blocks unsupported settings and prints a manual cutover/rollback checklist. It never applies changes.
- `current:doctor` inspects native configuration, Redis connectivity, binary architecture, state permissions, access gates, history schema and optional collector health without dispatching jobs or performing repairs.
- Native dashboards now include all eight operational summary cards, guarded source states and valid split-pool controls. Queue inventory live counts refresh automatically.
- Shared native state is counted once when web and worker hostnames differ; distinct supervisor generations remain separate.
- Completion rates retain jobs with unavailable duration data; runtime averages and missing metric windows remain explicitly unavailable. Workload estimates distinguish consuming capacity from draining processes.
- Native wait alerts can route to opt-in Laravel mail, Slack and Vonage notifications while preserving custom event listeners and shared cooldowns.
- Native batch retries can include verified Laravel failures absent from Current history. One user action continues bounded chunks, with pause and explicit recovery after uncertain requests. Durable dispatch claims survive captured-history pruning; exact database payload cleanup preserves concurrently replaced failures.
- `current:listen` watches application source and drains workers before restart. `current:purge` uses recorded process identity and Linux pidfds to clean up proven orphan workers while preserving active instances.
- Native automatic balancing defaults to Horizon-style proportional recommendations; the previous bounded rounding remains available. Approved exact tags can extend new history retention within the configured global cap.

Run `current:install` for migrations 17–20 and install the matching supervisor binary before using these changes. Published beta.2 is unchanged. Native execution remains experimental; finite tests do not establish production reliability across every topology.

## v0.1.0-beta.2 — 2026-10-27

- Optional Linux Go supervisor runs standard Laravel Redis workers without Horizon. The default Horizon backend remains supported.
- Native execution includes environment-specific pools, worker ranges and budgets, balancing, pause/resume/restart, deployment shutdown, queue inventory, metrics and batch investigation.
- Native failures have protected retry controls. Batch navigation supports database name/ID search and cursor pagination; retries remain bounded to captured, matching failures.
- Optional async job telemetry batches history writes into local SQLite or MySQL/MariaDB. Protocol 2 keeps producer job identities independent of history SQL and preserves canonical references after collection.
- Native maintenance commands clear waiting/delayed work, forget exact failed histories and reset metrics with explicit reconciliation and replay protection.
- Runtime SQL sampling runs in a bounded child process. Launcher output is forwarded without retaining its complete history. Collector maintenance transactions explicitly request the required SQL isolation.
- History migrations use Current's dedicated installer rather than application migration auto-discovery. Existing history and configuration are preserved during supported upgrades.
- Supervisor and collector artifact builds include checksums and license notices; private test evidence and local binaries are excluded from package archives.

### Upgrade preparation

Stop producers and workers before changing telemetry protocols, execution backends or recording modes. Drain retained buffers using the documented procedure, back up history, run `php artisan current:install` (`--force` in production), install matching binaries, refresh configuration caches and restart supervised processes. Protocol 2 requires a collector advertising identity protocol 2 and maintenance protocol 1. Do not perform a rolling mixed-protocol deployment.

Native execution and async collection remain optional and experimental. Read [native setup](docs/NATIVE-ENGINE.md), [async setup](docs/ASYNC-TELEMETRY.md) and [compatibility limits](docs/COMPATIBILITY.md) before enabling them. No universal performance or production-reliability guarantee is implied.

Published version tags remain immutable. This release does not replace the contents of beta.1.
