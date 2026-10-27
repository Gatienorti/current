# Changelog

## Unreleased

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

Published version tags remain immutable. These changes require a new release; they do not replace the contents of beta.1.
