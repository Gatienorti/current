# Contributing to Current

Start with a small bug fix, reproduction, documentation correction, or focused usability improvement. Discuss substantial features in an issue before building them. Read [AGENTS.md](AGENTS.md) for project boundaries and ARCHITECTURE.md for how observation differs from execution.

## Run the portable compatibility suite

You need Git, Composer 2, PHP with Redis, PDO SQLite, DOM, mbstring and PCNTL, and a disposable Redis server. The matrix creates temporary Composer consumers with their own dependencies; it does not need either of the maintainer's private applications.

```sh
# From the repository root, with a disposable Redis server available:
LENS_REDIS_HOST=127.0.0.1 php tests/compatibility/run.php laravel-12-floor
```

Use a PHP version appropriate for the selected row in `tests/compatibility/matrix.json`. Run without a row name to run every profile supported by that PHP environment. The GitHub Actions workflow chooses the appropriate PHP version for each row. Some historical profiles deliberately permit known dependency advisories in disposable test consumers; do not use those consumers as application templates.

The `adapt` and `vehify` matrix names identify pinned dependency combinations; those portable rows do not require private source. Actual host integration harnesses under `integrations/` and the original Compose preview are optional maintainer tooling. See [compatibility](docs/COMPATIBILITY.md) and development notes.

## Changes and validation

Keep changes focused and describe the problem, resulting behavior, and relevant checks in the pull request. Add realistic regression coverage for lifecycle, retry, release, concurrency, authorization, redaction, or storage changes. For documentation-only edits, verify examples and links rather than running unrelated application tests.

For interface changes, include before/after screenshots using synthetic data, and check both light and dark appearance. No frontend build is required; assets live under `resources/`.

Keep Horizon responsible for job execution. Do not infer success from absent observations or treat a source outline as a runtime trace. Optional integrations must preserve the application's behavior when collection fails.

Never include `.env` files, credentials, real customer payloads, production logs, or private application source in a report or contribution. Use the [security policy](SECURITY.md) for suspected vulnerabilities.

Contributions are provided under the repository's MIT license. Be respectful and constructive in discussions; focus feedback on the work.
