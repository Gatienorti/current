# Install Current

Current runs alongside an existing Horizon installation. Laravel 11.44+, 12 and 13 are declared; see [tested combinations](COMPATIBILITY.md). Horizon continues to execute jobs. No frontend build or additional service is required for the dashboard.

## Install the unpublished beta checkout

The package is not published. Its Composer identifier remains `horizon-lens/horizon-lens` until a distribution vendor/name is selected; do not assume it exists on Packagist. Add this checkout as a Composer path repository to a **disposable application** first:

```json
{
    "repositories": [{
        "type": "path",
        "url": "../horizon-lens",
        "options": {
            "symlink": true,
            "versions": {"horizon-lens/horizon-lens": "dev-main"}
        }
    }]
}
```

```sh
composer require horizon-lens/horizon-lens:dev-main
php artisan current:install
```

Composer discovers the provider. The installer publishes `config/current.php` without overwriting an existing configuration, creates `storage/current/current.sqlite`, and runs only package migrations on that connection. Repeating it preserves data. Production installation requires `--force`. PHP needs PDO SQLite for this default. The web and queue processes must share access to the history file; use a dedicated shared database connection for multiple hosts or ephemeral deployments.

Open `/current` while authenticated as an operator authorized by Horizon. Current requires both `viewHorizon` and Horizon's authorization callback, even in a local environment. Configure your existing Horizon authorization rather than disabling dashboard middleware. The package does not provide a login screen.

Restart long-lived producers/workers through your usual deployment process. Run Laravel's scheduler: the provider registers `current:prune` hourly. The default retention is seven days, capped at thirty. The installer does not start workers or a scheduler. Refresh Laravel's config/route caches after configuration changes.

## Check your first installation

1. Open `/current` as an authorized Horizon operator. The dashboard should show Horizon status and queues; Inactive can be correct when no master is running.
2. Dispatch a harmless job on a Redis connection listed in `queue_connections`. Confirm that it appears in Monitoring and changes state when your worker consumes it. Current does not import old Horizon history.
3. Inspect its Args/Metadata and confirm the capture policy fits your application. Add named steps only where they make investigation useful.
4. Use a deliberately failing test job in a development or staging environment to check failure location, Retry job, live map and the debugging-prompt modal. A retry executes the job again; it does not repair the cause.
5. Confirm Laravel's scheduler is running for hourly pruning. Use `php artisan current:prune` when you deliberately want to remove expired Current observations.

For the first beta, try ordinary investigations in ADAPT and Vehify over several days. Note the action, expected result and observed result when reporting a problem. Share sanitized examples rather than raw job payloads. This usage period is still pending; automated tests do not replace it.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `/current` returns 403 | Log in through your application and check both `viewHorizon` and Horizon's authorization callback. Local mode does not bypass Current's gate. |
| No jobs appear | Check `CURRENT_ENABLED`, the Redis connection allowlist, worker restarts and shared history storage. Only newly observed work appears. |
| Empty execution map | Add `Current::step` inside the job handler. Automatic queue events describe attempts, not internal code execution. |
| Args/Metadata unavailable | Encrypted/unsupported/oversized data is omitted. Raw retries may show a labeled earlier snapshot if retained. |
| Failed job cannot retry | Horizon must still retain its failed payload. Check `CURRENT_ALLOW_RETRIES`; a worker must consume the original queue. |
| Storage errors or observations missing | Ensure web and worker processes can access the dedicated database. Review `storage/logs/current-health.json` when present and application logs. |
| Configuration changes do not apply | Rebuild Laravel's config/route caches and restart long-lived producers/workers through your deployment process. |

## What appears automatically

New normal object dispatches through the configured Redis queue connections capture job properties into Args, plus safe standard policy metadata. Job state, attempts, queue, timings, tags permitted by configuration and exception class/location appear without adding step calls. Data is captured at enqueue, not continuously as the handler changes it. Args represent serialized job properties, not necessarily the original constructor arguments. Model identifiers show stored identifiers, not expanded database models.

Current reads a bounded data format without PHP `unserialize`, class loading, object construction or model queries. It never persists the raw serialized command. Credential-like keys and assignment strings are redacted before persistence. This is not a universal detector for personal or business-sensitive data: review what your jobs carry. To disable automatic Args/Metadata, set `CURRENT_CAPTURE_PAYLOAD=false`. Explicit exporters still work when automatic capture is disabled.

Encrypted jobs, closure commands and unsupported/malformed payloads show Unavailable. Oversized payloads are omitted. Old history and raw retry pushes are not backfilled. Recording failures do not change job execution. An empty execution map means no internal steps were observed, not that the job did no work.

Defaults allow the queue connection named `redis`. Add your other Redis **connection names** to `queue_connections` in `config/current.php`; these are not queue names. Tags default to an empty allowlist: add approved prefixes to `tag_prefixes`. Exception messages remain omitted unless you configure a sanitizer; class, source location and bounded stack context are available independently.

## Optional detail in one job

```php
use Current\Current;

public function handle(): void
{
    Current::step('Validate order', fn () => $this->validateOrder());

    // Use the normal return value from the instrumented operation.
    $reservation = Current::step('Reserve inventory', fn () => $this->reserveInventory());

    Current::step('Charge payment', fn () => $this->chargePayment($reservation));
}

public function currentArguments(): array
{
    return ['order_id' => $this->orderId];
}

public function currentMetadata(): array
{
    return ['workflow' => 'checkout'];
}
```

This is a fragment for an existing job: keep its normal queue traits, constructor and business methods. Start with two or three meaningful operations rather than wrapping every line. If Charge payment throws, the map records that step as failed while the earlier completed steps remain visible. Nested steps are supported.

Steps preserve return values and rethrow the original exception. Use fixed labels. Exporters replace automatic values for their field. For existing jobs you cannot edit, set `job_context` to a callable such as `[App\Support\CurrentContext::class, 'capture']` in the config file; its static method receives the job and returns `arguments` and/or `metadata`. This form supports config caching. Missing keys fall back to job exporters, then automatic data; return an explicit empty array for a field to omit its automatic contents. Callback failure is marked unavailable and never falls back to raw data.

Snapshots use the same bounds/redaction for automatic and explicit values. Defaults: 16 KiB JSON, 100 entries, depth 5, 2,048 characters per string; decoding is capped at 256 KiB input, 4,000 nodes and depth 20. Reference values are marked omitted. Internal steps always require instrumentation; source possibilities are shown separately.

## Source-outline cache

Unchanged source outlines are cached automatically inside each worker. No extra service or install step is required. `source_cache_entries` defaults to 128; set it to 0 to disable caching (hard maximum 512). The cache also caps retained outline JSON at 2 MiB. It keeps no raw source or syntax trees and disappears when the worker exits.

Content hashes invalidate edited files even if size/timestamps are unchanged. Source roots and size limits are checked before reuse. `max_source_bytes` defaults to 256 KiB with a hard 1 MiB ceiling; `max_source_operations` defaults to 100, capped at 1,000. Reading/hashing still occurs per attempt; new/evicted entries still need parsing. Restart long-lived workers on deployment so their loaded PHP code changes too. This is not shared across workers and makes no production performance guarantee.

## Legacy installations and alternate storage

`HorizonLens\Lens::step`, `lensArguments`, `lensMetadata`, `lens:prune`, `horizon-lens.php`, table names and internal route names remain compatible. New installs use `current.php`, `/current`, `Current\Current` and `current:*` commands. If both config files exist, explicit Current keys take precedence. The existing labs intentionally retain `/horizon-lens` and their isolated databases.

To use MySQL or MariaDB, define a dedicated Laravel database connection with an empty table prefix, point `CURRENT_CONNECTION` to its name, and run `current:install` again for that database. Configure appropriate connection/lock timeouts; recorder isolation does not imply a universal driver timeout guarantee. Installation does not copy history between databases. MySQL 8.4 strict-mode installation, success/retry recording, dashboard/details and pruning have been verified in a disposable container; MariaDB 11.4.13 passes the same smoke checks using both Laravel's `mariadb` and `mysql` drivers. Broader database version/concurrency/load testing remains separate.

## Removal

Remove the Composer dependency, clear/rebuild deployment caches and restart long-lived workers. The database and published configuration are deliberately retained. Delete those only when you no longer need the history; Composer removal never drops application data. Remove any custom `Current::step` imports/exporters before removing their package dependency.

## Reproduce installation checks

`tests/installation/smoke.php` accepts the absolute path of a disposable Laravel application already installed through Composer. Run it with `php tests/installation/smoke.php /path/to/disposable-app`, optionally adding `mysql` or `mariadb`; use `mariadb mysql` to check MariaDB through Laravel’s MySQL driver. It assumes the isolated lab Redis host `lens-redis` and, for database checks, the disposable `current-install-mysql` or `current-install-mariadb` container and `current` database; it must not be pointed at an ordinary application. It verifies normal provider discovery, repeat install, `/current` authorization, actual queue completion/retry, automatic redaction, the step API, rendered job details and retention cleanup. Default and config/route-cached boots are checked separately. It intentionally prunes its synthetic history.

## Retrying a failed job

Open a retained failed job and choose **Retry job**. Current delegates to Horizon, opens the new record and follows its recorded steps at one-second intervals while active. Horizon must still retain the failed payload, and a worker must consume its original queue. The original record stays Failed; **View retry** reopens the submitted retry. Repeated submissions from that original record do not queue additional copies.

The action uses the same Horizon dashboard authorization plus CSRF protection. Set `CURRENT_ALLOW_RETRIES=false` (or `allow_retries => false`) to disable it, then rebuild configuration caches as appropriate. If the outcome cannot be confirmed, check Horizon before retrying elsewhere; Current deliberately keeps its claim to avoid an automatic duplicate. Args/Metadata on raw retries can remain uncaptured. Current can display an earlier retained snapshot with an Earlier enqueue label, source link and original capture timestamp; it does not copy or recapture that data. Expired or ambiguous ancestry stays unavailable. Named internal steps still require instrumentation.

## Copy a debugging prompt

On failure details, select **Debug with AI**. The modal shows the selected attempt's captured exception, sanitized stack, recorded steps and Args/Metadata provenance. Review it, then use the clipboard icon to copy it into your coding agent. Nothing is sent automatically and no AI key is required. Copy errors select the text for manual copying. Escape or Close dismisses the modal; automatic refresh pauses while it is open.

## Updating the beta checkout

After updating the package, run `php artisan current:install` to apply any new package migrations (use `--force` in production), review newly documented settings, rebuild deployment caches and restart long-lived processes. The installer preserves existing configuration; it does not merge new settings into a published file. Existing captured history remains subject to its original retention. The unpublished Composer identifier is still `horizon-lens/horizon-lens`; the public vendor namespace is awaiting selection, so no public install command or release tag is claimed yet.
