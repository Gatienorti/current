# Current native supervisor (experimental)

This is the canonical Linux Go supervisor source. Acceptance fixtures under `tests/native/fixtures` exercise this directory through the public PHP launcher.

```sh
cd supervisor
./build-release.sh development
```

Build on the target Linux architecture using Go 1.25. Install the executable at the explicitly configured `current.native.binary` path; verify its accompanying SHA256 checksum. No binary is downloaded or executed automatically during Composer installation. CI artifact builds are configured for Linux amd64/arm64; a local ARM64 test is not proof that an unpublished CI run passed.

The public interface is `php artisan current:supervise`. PHP validates queue/application configuration and supplies a private JSON file; Go owns worker processes, local ownership, restart bounds, controls and graceful drain. A launcher-parent watcher drains workers if the PHP launcher disappears. Use a real process manager/container init to restart the launcher and reap orphaned exit records. Terminal health describes the last instance; it does not assert that the process manager restarted it.

The size/time autoscaler runs in the existing PHP launcher, reads Laravel's configured Redis connection, and sends bounded target changes to Go. Go removes only surplus workers and retains their capacity until they exit. Each instance has a configurable `worker_limit` (default and maximum 256) for live worker slots, separate from per-pool min/max. No new worker replaces a draining slot early. Different instance names may consume a shared Laravel queue; allocation budgets are per instance, not a global cluster budget. Local file locks are not multi-host fencing.

See [native setup](../docs/NATIVE-ENGINE.md) for verified scope and remaining parity work.
