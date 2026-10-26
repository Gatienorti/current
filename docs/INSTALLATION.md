# Install Current

Current runs alongside an existing Horizon installation. Laravel 11.44+, 12 and 13 are declared; see [tested combinations](COMPATIBILITY.md). Horizon continues to execute jobs. No frontend build or additional service is required for the dashboard.

## 1. Install the development beta

The source is public at [Gatienorti/current](https://github.com/Gatienorti/current), but the package is not on Packagist yet. Its Composer identifier is `gatienorti/current`. Install the development branch in a **disposable application** first:

```sh
composer config repositories.current vcs https://github.com/Gatienorti/current
composer require gatienorti/current:dev-main
php artisan current:install
```

Alternatively, contributors can use a local checkout as a Composer path repository:

```json
{
    "repositories": [{
        "type": "path",
        "url": "../current",
        "options": {
            "symlink": true,
            "versions": {"gatienorti/current": "dev-main"}
        }
    }]
}
```

```sh
composer require gatienorti/current:dev-main
php artisan current:install
```

Composer discovers the provider. The installer publishes `config/current.php` without overwriting an existing configuration, creates `storage/current/current.sqlite`, and runs only package migrations on that connection. Repeating it preserves data. Production installation requires `--force`. PHP needs PDO SQLite for this default. The web and queue processes must share access to the history file; use a dedicated shared database connection for multiple hosts or ephemeral deployments.

## 2. Open Current with your Horizon access

Open `/current` while authenticated as an operator authorized by Horizon. Current requires both `viewHorizon` and Horizon's authorization callback, even in a local environment. Configure your existing Horizon authorization rather than disabling dashboard middleware. The package does not provide a login screen.

Restart long-lived producers/workers through your usual deployment process. Run Laravel's scheduler: the provider registers `current:prune` hourly. The default retention is seven days, capped at thirty. The installer does not start workers or a scheduler. Refresh Laravel's config/route caches after configuration changes.

## 3. See your first job

In the disposable application, generate a harmless queued job:

```sh
php artisan make:job CurrentSetupCheck
```

Keep the generated queue traits and replace its `handle()` method with:

```php
public function handle(): void
{
    \Current\Current::step('Check input', fn () => ['ready' => true]);
    \Current\Current::step('Finish setup check', fn () => true);
}
```

Dispatch it to a dedicated queue, then consume only that queue:

```sh
php artisan tinker --execute='App\Jobs\CurrentSetupCheck::dispatch()->onConnection("redis")->onQueue("current-setup-check");'
php artisan queue:work redis --queue=current-setup-check --stop-when-empty --tries=1
```

Open **Queues & Jobs → Monitoring**, filter Queue to `current-setup-check`, and open the job. Expect **Completed**, an attempt and the two named execution steps. Horizon status can still be Inactive if you used a one-off worker rather than a Horizon master. Inspect Args/Metadata and confirm the capture policy fits your app. Jobs without step calls still have lifecycle/attempt information, but no automatically observed internal execution map.

Next, use a deliberately failing fixture in development/staging to check failure details and Debug with AI. Retry requires Horizon's retained failed payload and executes the job again. Keep Laravel's scheduler running for hourly pruning; `php artisan current:prune` removes only expired Current history.

## 4. Enable optional integrations

Start with jobs working. Optional collectors are **off by default**; adding a tab or installing Reverb does not automatically export payloads.

| Area | Minimum setup | First place to check |
| --- | --- | --- |
| Incoming webhooks | Set `CURRENT_WEBHOOKS_ENABLED=true`; add `current.webhook:provider` to an existing validated receiver route. Preserve its signature verification and middleware. | Webhooks → Dashboard → Incoming receipts |
| Outgoing webhooks | Set `CURRENT_OUTGOING_WEBHOOKS_ENABLED=true`; add exact destination URLs under `webhooks.outgoing.destinations`. The sender must use Laravel's `Http` client. | Webhooks → Dashboard → Outgoing deliveries |
| Broadcast submissions | Use an existing configured Reverb or Pusher connection; set `CURRENT_BROADCASTS_ENABLED=true`; approve exact event names and channel patterns under `broadcasts`. | Broadcasts → Dashboard → Submissions |
| Channel authorization failures | Set `broadcasts.authorization.enabled=true` and approve exact auth route names or templates (often `broadcasting/auth`). | Broadcasts → Authorization failures |

Rebuild configuration/route caches and restart the application's long-lived producers/workers after changing settings. Use synthetic requests/events in development first. Follow the complete [incoming](#incoming-webhook-proof-of-concept), [outgoing](#outgoing-webhook-proof-of-concept), [Reverb](#optional-reverb-broadcast-observation) and [authorization](#broadcast-authorization-failures) examples below. No additional frontend build is needed.

**What requires extra configuration:** webhook Payload/Metadata/Response exports, provider event identity/grouping, broadcast Payload/Metadata and exact channels, and sanitized exception messages. Missing capture is displayed as unavailable, not reconstructed. Existing history is not imported. Begin with metadata-only observation, then explicitly export fields useful to your team.

For beta feedback, use ordinary investigations over several days and record the action, expected result and observed result. Share sanitized examples rather than raw payloads. This usage period is still pending; automated tests do not replace it.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `/current` returns 403 | Log in through your application and check both `viewHorizon` and Horizon's authorization callback. Local mode does not bypass Current's gate. |
| No jobs appear | Check `CURRENT_ENABLED`, the Redis connection allowlist, worker restarts and shared history storage. Only newly observed work appears. |
| Empty execution map | Add `Current::step` inside the job handler. Automatic queue events describe attempts, not internal code execution. |
| Args/Metadata unavailable | Encrypted/unsupported/oversized data is omitted. Raw retries may show a labeled earlier snapshot if retained. |
| Failed job cannot retry | Horizon must still retain its failed payload. Check `CURRENT_ALLOW_RETRIES`; a worker must consume the original queue. |
| Storage errors or observations missing | Ensure web and worker processes can access the dedicated database. Review `storage/logs/current-health.json` when present and application logs. |
| Broadcasts tab is missing | Current detects installed Reverb or the selected Reverb/Pusher driver on boot. Installing it later requires normal config/process refresh; not a Current reinstall. An explicit `broadcasts.visible` override controls navigation only. |
| Webhook/broadcast dashboard is empty | Confirm collection is enabled and its route/destination/event/channel allowlists match. Only new observations are collected. Read Observation coverage to distinguish disabled capture from empty retained history. |
| Migration table exists but a feature table is missing | Run `php artisan current:install` after updating, then restart long-lived processes. Existing config files are preserved; add new optional settings explicitly. |
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

`Current\Current::step`, `lensArguments`, `lensMetadata`, `lens:prune`, `current.php`, table names and internal route names remain compatible. New installs use `current.php`, `/current`, `Current\Current` and `current:*` commands. If both config files exist, explicit Current keys take precedence. The existing labs intentionally retain `/current` and their isolated databases.

To use MySQL or MariaDB, define a dedicated Laravel database connection with an empty table prefix, point `CURRENT_CONNECTION` to its name, and run `current:install` again for that database. Configure appropriate connection/lock timeouts; recorder isolation does not imply a universal driver timeout guarantee. Installation does not copy history between databases. MySQL 8.4 strict-mode installation, success/retry recording, dashboard/details and pruning have been verified in a disposable container; MariaDB 11.4.13 passes the same smoke checks using both Laravel's `mariadb` and `mysql` drivers. Broader database version/concurrency/load testing remains separate.

## Removal

Remove the Composer dependency, clear/rebuild deployment caches and restart long-lived workers. The database and published configuration are deliberately retained. Delete those only when you no longer need the history; Composer removal never drops application data. Remove any custom `Current::step` imports/exporters before removing their package dependency.

## Reproduce installation checks

For a complete clean jobs-only → optional integrations check, run `php tests/installation/fresh.php` **inside the isolated lab**. It creates a new Laravel 13 project under `/tmp/current-beta-install-*`, installs the local path package normally, checks default and config/route-cached boots, then installs Reverb and exercises synthetic incoming → queued job → outgoing retries/broadcasts, authorization rejection, investigation pages and cleanup. Outgoing requests are intercepted; Reverb listens only on loopback and stops afterward. Logs and the resolved lockfile stay in the temporary consumer. This requires Composer network/cache access and the lab Redis host `lens-redis`; do not run it against a host application.

`tests/installation/smoke.php` accepts the absolute path of a disposable Laravel application already installed through Composer. Run it with `php tests/installation/smoke.php /path/to/disposable-app`, optionally adding `mysql` or `mariadb`; use `mariadb mysql` to check MariaDB through Laravel’s MySQL driver. It assumes the isolated lab Redis host `lens-redis` and, for database checks, the disposable `current-install-mysql` or `current-install-mariadb` container and `current` database; it must not be pointed at an ordinary application. The jobs-only smoke verifies normal provider discovery, all 11 package migrations, default-off optional collectors, untouched application schema, repeat install, `/current` authorization, actual queue completion/retry, automatic redaction, the step API, rendered job details and retention cleanup. Default and config/route-cached boots are checked separately. It intentionally prunes its synthetic history.

## Retrying a failed job

Open a retained failed job and choose **Retry job**. Current delegates to Horizon, opens the new record and follows its recorded steps at one-second intervals while active. Horizon must still retain the failed payload, and a worker must consume its original queue. The original record stays Failed; **View retry** reopens the submitted retry. Repeated submissions from that original record do not queue additional copies.

The action uses the same Horizon dashboard authorization plus CSRF protection. Set `CURRENT_ALLOW_RETRIES=false` (or `allow_retries => false`) to disable it, then rebuild configuration caches as appropriate. If the outcome cannot be confirmed, check Horizon before retrying elsewhere; Current deliberately keeps its claim to avoid an automatic duplicate. Args/Metadata on raw retries can remain uncaptured. Current can display an earlier retained snapshot with an Earlier enqueue label, source link and original capture timestamp; it does not copy or recapture that data. Expired or ambiguous ancestry stays unavailable. Named internal steps still require instrumentation.

## Copy a debugging prompt

On failure details, select **Debug with AI**. The modal shows the selected attempt's captured exception, sanitized stack, recorded steps and Args/Metadata provenance. Review it, then use the clipboard icon to copy it into your coding agent. Nothing is sent automatically and no AI key is required. Copy errors select the text for manual copying. Escape or Close dismisses the modal; automatic refresh pauses while it is open.

## Updating the beta checkout

After updating the package, run `php artisan current:install` to apply any new package migrations (use `--force` in production), review newly documented settings, rebuild deployment caches and restart long-lived processes. The installer preserves existing configuration; it does not merge new settings into a published file. Existing captured history remains subject to its original retention. The development package is `gatienorti/current`; no Packagist release or beta tag has been published.

## Incoming webhook proof of concept

This optional slice observes existing inbound routes; it does not register a production receiver, verify signatures, send webhooks or replay requests. After updating Current, run `php artisan current:install` to add its receipt/link tables. Set `CURRENT_WEBHOOKS_ENABLED=true` and rebuild configuration caches. Jobs-only installations can leave collection disabled.

Opt in an existing route using a fixed provider label:

```php
Route::post('/webhooks/provider', ExistingWebhookController::class)
    ->middleware('current.webhook:provider');
```

Keep your application's existing signature verification, authentication, rate limits and CSRF configuration. Place the observer before any route middleware whose rejection you want recorded; requests rejected before the observer runs are outside coverage. After your existing validator has produced a result, it may set:

```php
$request->attributes->set('current.webhook.signature', $valid ? 'valid' : 'invalid');
```

This is a reported result, not validation by Current. Without it, status is Unknown. Never derive it from an unverified request field. Provider labels accept only letters, digits, underscores and hyphens, up to 80 characters.

Open `/current/webhooks` for provider, HTTP-status and UTC receipt-time filters. A receipt detail shows the HTTP response separately from up to 100 job dispatches observed while that handler was active. Existing job details provide failure investigation and maps. Request bodies, headers, raw signatures and query strings are not automatically captured; only the configured label, route template, method, response/timing, signature state and exception class are stored. Receipt IDs are independent for every request, including repeated deliveries.

The existing Current authorization and retention apply. `current:prune` also removes expired receipts and links. Missing finishes stay unknown. This proof of concept does not link deferred after-response dispatches, external queues, follow-on jobs or a dispatch committed after the observer exits; missing links do not prove no work occurred. Octane/concurrent-coroutine execution, provider-specific adapters, outbound delivery, full compatibility-matrix and MySQL/MariaDB behavior for these new tables remain unvalidated.

## Outgoing webhook proof of concept

Run `php artisan current:install` after updating to add the outgoing-attempt table. In `config/current.php`, keep the existing webhook settings and configure:

```php
'outgoing' => [
    'enabled' => env('CURRENT_OUTGOING_WEBHOOKS_ENABLED', false),
    'destinations' => [
        'partner' => 'https://partner.example/webhooks/orders',
    ],
],
```

Place this under `webhooks`, set `CURRENT_OUTGOING_WEBHOOKS_ENABLED=true`, rebuild your configuration cache, and restart queue workers. Incoming collection can remain disabled. Matching uses exact scheme, host, normalized port and path; query values do not affect matching. Choose safe destination labels and paths: those are retained. Automatic capture excludes credentials, query strings, fragments, headers, bodies and exception messages. Explicit approved context exports are described below.

Open `/current/webhooks/outgoing` for destination, HTTP-status and time filters. Each matching Laravel `Http` client attempt records its HTTP response or transport-error class and duration. HTTP retries create separate rows. When sent inside a recorded queue attempt, it links to that Current job; normal web requests have no job link. Expired job details may no longer be available.

This observes your existing sender; it does not send, retry or replay requests itself. Direct Guzzle/cURL, third-party SDK clients, custom clients bypassing Laravel's handler stack and clients suppressing global middleware are outside this adapter. A response is not recipient business acknowledgement. Missing completion remains unknown. This slice is verified in the Laravel 12.69.3 isolated SQLite/Redis lab; Octane/coroutines, database variants and a broader version matrix remain unvalidated.

## Webhook event identity and safe context

Run `php artisan current:install` for migration 6, then enable `CURRENT_WEBHOOK_CONTEXT=true` and restart workers after rebuilding configuration caches. This is independent of enabling incoming/outgoing observation. Existing records are not backfilled.

After your normal incoming validation, explicitly export approved fields on the request:

```php
$request->attributes->set('current.webhook.context', [
    'event_id' => $validatedEventId,
    'event_type' => 'order.updated',
    'payload' => ['order_id' => $orderId, 'status' => $status],
    'metadata' => ['source' => 'partner'],
    'response' => ['accepted' => true], // optional application-reported response data
]);
```

For a configured outgoing Laravel HTTP destination, attach context to that request:

```php
Http::withOptions(['current_webhook' => [
    'event_id' => $eventId,
    'event_type' => 'order.updated',
    'payload' => ['order_id' => $orderId, 'status' => $status],
    'metadata' => ['source' => 'orders'],
]])->post($destination, $body);
```

Outgoing response data requires an optional `webhooks.response_context` callable taking a PSR response and destination label. For example, in a service provider:

```php
config()->set('current.webhooks.response_context',
    fn ($response, $destination) => ['status' => $response->getStatusCode()]
);
```

Return only approved data. Do not consume or modify response streams in this callback; Current does not read them automatically. Callback exceptions are caught and shown as Capture unavailable. Request exports cannot predeclare an outgoing response. Incoming response exports are application-reported, not automatically extracted from the response.

Snapshots reuse the existing context bounds: 16 KiB per panel by default, maximum 64 KiB; depth, item and string limits also apply. Over-byte-limit snapshots are omitted, other limits are labeled. Sensitive keys (including tokens, passwords, credentials and signatures) are redacted before storage. Objects/resources are omitted. Key-based redaction is not universal personal-data detection: export an allowlist, not an entire body. Event IDs/types must be strings up to 160 bytes and are supplied by your application, not inferred or authenticated by Current.

Both lists provide an Outcome filter and expandable event-ID/type/HTTP-status filters; all combine. Incoming “Linked job failed” means a retained linked job's latest observed outcome is failed, not that the HTTP request failed. “Completion not observed” remains unknown. Duplicate IDs do not merge observations or imply duplicate execution.

## Following a webhook through a job

Incoming receipt details list outgoing attempts from directly linked jobs. Job details show Related webhooks when retained links exist. Outgoing details link back to incoming receipts through the sending job; delivery rows open the exact sending attempt. This needs no additional capture setting beyond incoming route and outgoing destination opt-in. Panels show at most the latest 100 records per direction and omit expired history. Matching event IDs, later retry records, child jobs and deferred work do not create inferred links.

## Grouping outgoing retries

Run `php artisan current:install` for migration 7 and restart workers. Use the fresh-request helper once per logical delivery:

```php
Http::currentWebhook()
    ->retry(3, 100)
    ->post($configuredDestination, $body);
```

The helper generates an ID shared by that request's HTTP retries. For retries across queue executions, persist a stable delivery ID on the sending job and reuse it:

```php
Http::currentWebhook($this->deliveryId)->retry(3, 100)->post($destination, $body);
```

A new event/delivery needs a new ID. Do not reuse a helper-created pending request for unrelated sends. Existing senders can instead supply `withOptions(['current_delivery_id' => $deliveryId])`. IDs must be nonempty strings of at most 160 bytes; missing/invalid IDs leave attempts independent. This option is separate from `current_webhook` context exports and works with context capture disabled. No ID is added to transmitted headers or bodies.

Grouping is scoped to the configured destination label, normalized endpoint and HTTP method; only a SHA-256 identity is persisted. Event IDs alone never group attempts. Query parameters do not form part of the endpoint scope: include any tenant/logical-recipient distinction in your delivery ID. Ordinary Laravel HTTP calls and existing history remain independent; Current cannot safely infer call boundaries from its per-attempt middleware.

The outgoing list shows one row per retained group with its latest started attempt, attempt count and elapsed observation span. Filters match any retained attempt in a group, then show its latest state. Open a delivery for up to 100 latest attempts with response/error, duration, gap (or overlap/unknown), and exact job-attempt links. Selecting an earlier attempt shows that attempt's context, not the latest response. Missing completions are explicitly unknown, and expiry can remove earlier history. This groups observations; it does not implement retries, deduplication, exactly-once delivery or recipient acknowledgement.

## Optional Reverb broadcast observation

Broadcasts appears automatically when Laravel Reverb is installed, or the application's selected broadcast connection uses the Reverb or Pusher driver (including a remote Reverb server). Without either, the tab is hidden and its routes return 404. `broadcasts.visible` in Current config accepts `null` for detection, or `true`/`false` as an explicit override. This controls visibility, not collection or access authorization. Server reachability never hides the tab.

Installing Reverb later does not require reinstalling Current: complete normal Reverb configuration, rebuild Laravel's config cache and reload long-lived application/queue workers. Detection is evaluated from the current process's classes/configuration, not a permanent installation flag.

If Composer refuses a new Reverb installation because a locked HTTP dependency is incompatible, inspect the solver message and proposed changes. In the fresh Laravel 13 acceptance fixture, `composer require laravel/reverb:^1.7 --with-all-dependencies` resolved the conflict by selecting compatible HTTP packages. This is a Reverb dependency-resolution step, not a requirement to upgrade or downgrade an existing application's dependencies blindly. Existing configured Reverb installations need no additional Composer action for Current.

Run `php artisan current:install` for migration 8, set `CURRENT_BROADCASTS_ENABLED=true`, and add approved names/patterns to `config/current.php`:

```php
'broadcasts' => [
    'visible' => null,
    'enabled' => env('CURRENT_BROADCASTS_ENABLED', false),
    'events' => ['ChatMessageSent', 'workout.analysis.completed'],
    'channels' => ['private-chat.*', 'private-user.*'],
],
```

Rebuild configuration caches and restart workers after changes. Collection defaults off; empty event/channel lists capture nothing. Event names match exactly. Channel matching uses Laravel's normalized channel names, including `private-` and `presence-` prefixes. By default only configured matching patterns are stored, not concrete channel IDs. Choose safe patterns; an exact configured name will be retained literally.

At startup, enabled collection adapts standard configured Reverb and Pusher connections to Current's shared observed broadcaster. It preserves Laravel's Pusher/Reverb construction, channel authorization and send behavior. Supported drivers are standard Laravel Reverb and Pusher; custom replacements, Ably and direct SDK calls are not observed. Existing driver credentials/timeouts are used; Current does not install or start Reverb in the host application.

Open `/current/broadcasts` for event, outcome, time and approved-pattern filters. Details show duration/error class and the recorded sending job attempt when available. Queued and immediate submissions are supported; immediate events outside workers have no job link. Payloads, exact channel names and error messages are excluded by default; explicit investigation capture options are described below. Socket IDs are not automatically captured. “Accepted by broadcaster” means the call returned without an exception—not client receipt, business processing, subscriber count or server health. Missing completion stays unknown. Standard Current authorization, retention and pruning apply.

## Pusher broadcast observation

Existing Laravel Pusher applications use the same Current broadcast settings above: enable `CURRENT_BROADCASTS_ENABLED`, approve event names/channel patterns, rebuild configuration caches and restart long-lived producers/workers. Keep the standard `pusher` driver in your application's broadcasting configuration; Current applies its internal wrapper at boot. Existing credentials, cluster/host, TLS, client options and channel authorization remain Laravel's responsibility. No Reverb installation or extra Current dependency is required; the application must already have Laravel's Pusher SDK dependency installed.

The Broadcasts tab detects a selected Pusher connection. If Pusher is used only as a secondary connection, set `broadcasts.visible=true`. Visibility alone does not enable recording. Multiple named Pusher/Reverb connections retain their names in observations.

VERIFIED: mocked HTTP tests exercise the actual installed Pusher SDK, immediate Laravel events, payload/socket forwarding, private-channel authorization, rejected sends, and unavailable observation storage. Actual hosted Pusher delivery and browser receipt are UNVALIDATED. Both standard Laravel Pusher and Reverb drivers also pass the ADAPT integration against a real loopback Reverb protocol server: immediate/queued submissions, linked jobs, channel authorization, invalid-signature rejection and observer-storage failure. This validates the Pusher-compatible protocol locally, not the hosted Pusher service.

## Broadcast investigation details

Run `php artisan current:install` for migration 9. Submission details now include a plain-language outcome, recorded timeline, public/private/presence types for approved channels, related submissions from the same retained job, and related incoming/outgoing webhooks when recorded job links exist. Job details link back to their broadcast submissions.

Queue timing is shown only for a retained observed enqueue/worker attempt. The enqueue→attempt interval includes configured delay and any earlier attempts, so it is not pure queue latency. Immediate or expired/unlinked submissions show queue timing unavailable. Clock-order inconsistencies are labeled rather than converted into positive durations.

For optional snapshots, configure runtime callbacks in an application service provider (under the effective `current` settings):

```php
config()->set('current.broadcasts.context', function ($event, $approvedChannels, $payload) {
    return [
        'payload' => array_intersect_key($payload, array_flip(['order_id', 'status'])),
        'metadata' => ['source' => 'orders'],
    ];
});
```

Export only approved fields. Payload/metadata capture is off without this callback; it never reruns broadcastOn/broadcastWith. The shared context encoder bounds bytes, items, depth and strings, redacts sensitive keys, and labels omitted/limited/failed snapshots. Redaction does not identify all personal data. Do not export raw messages, credentials, socket IDs or entire bodies indiscriminately.

`broadcasts.capture_channels=true` separately opts into up to 100 exact names matching approved patterns; it defaults false. Channel names may contain user/tenant identifiers. Unsupported/oversized snapshots are explicitly labeled; older observations are not backfilled.

Failure details retain source-root-limited exception location and bounded stack frames without arguments. Error messages require the separate `broadcasts.message_sanitizer` callback; the jobs message sanitizer does not enable them. For example, configure a callable that returns only an approved diagnostic string. If it fails, the original submission outcome/exception remains intact and additional capture is labeled unavailable. The exception location is not a root-cause assertion.

Quick views isolate failed, slow and unfinished submissions. `broadcasts.slow_ms` defaults to 1000; it measures observed submission duration, not browser delivery latency. All views combine with filters and remain shareable via the authorized URL. Related submissions share a recorded job; later Horizon retry records and child jobs are not inferred. Payload comparisons and broadcast AI prompts are described below; client receipt and live subscription telemetry remain outside this implementation.

### Group repeated incoming events

Run the normal package migration command after updating (migration 10 adds the nullable receipt grouping key). Inside an opted-in `current.webhook:provider` handler, after validating the request and trusting its event identity, set:

```php
$request->attributes->set('current.webhook.group', [
    'event_id' => $validatedEventId,
    'scope' => $providerAccountId,
]);
```

Both values must be nonempty strings of at most 160 bytes. Use the provider's stable **event ID**, not a unique delivery-attempt ID. Scope must distinguish accounts/tenants, subscriptions or environments where event IDs can collide; for a single account, use a stable explicit account label. The key also includes provider label, route template and HTTP method. Route parameters are not part of the route template: include their account distinction in scope. Different event types sharing an ID namespace must be distinguished in the supplied identity/scope.

Only a hash of this identity is persisted. This does not enable payload capture or display the raw event ID; those remain separate explicit context exports. Grouping is applied when the application's response is observed, including error responses. A missing completion before identity capture remains an independent receipt. Invalid reported signatures, missing/invalid identities, and historical receipts stay independent; Current does not authenticate the identity itself. Set the attribute only after authentication/validation, and before work that may fail.

Incoming lists show the latest received receipt per group, retained receipt count and HTTP-error/missing-completion counts. All filters combine on an individual receipt before grouping: an earlier matching failure can show a group whose latest receipt succeeded or falls outside the selected time range. Details show the latest 100 retained receipts, their own responses and linked jobs; selecting one shows its own context. Expired receipts and jobs are excluded. Repeated identities do not prove a provider retry reason, duplicate side effects, or successful business processing. This adds observation only, not deduplication or replay.

### Broadcast authorization failures

Run `php artisan current:install` for migration 11. Add these settings inside `broadcasts` in `config/current.php`:

```php
'authorization' => [
    'enabled' => true,
    'routes' => [], // Exact route names if your authorization routes are named.
    'paths' => ['broadcasting/auth'], // Exact route templates, no leading slash.
],
```

Use `php artisan route:list --path=broadcasting` to check your actual route template/name, including any API prefix. Laravel's default authorization route may be unnamed; approving its path avoids changing the route. Keep your existing authentication and middleware. Rebuild configuration caches and restart long-lived processes as usual. This collector is independently opt-in; it does not require submission collection to be enabled. Current's overall enabled flag still applies. Broadcast navigation uses the Reverb/Pusher detection/visibility override.

Only HTTP responses of 400 or higher on explicitly approved routes are captured, after Laravel renders the response. The **Authorization failures** page combines route, HTTP status, time and approved-channel-pattern filters. It retains a route name (when explicitly approved) or route template, method, observed response time, HTTP status, matching approved channel patterns and type. It reads only `channel_name` from input. Exact channel names require both a match against `broadcasts.channels` and `broadcasts.capture_channels = true`; otherwise no concrete channel is persisted. Missing, oversized, malformed or unapproved channels are labeled unknown. User identifiers, socket IDs, credentials, request headers and response bodies are not retained.

A 403 is a forbidden HTTP response, not proof that a channel callback rejected access. Authentication middleware, CSRF/session errors, rate limiting and application exceptions can fail earlier. Redirects, successful responses, requests rejected before routing/PHP, WebSocket handshakes and client acknowledgements are outside this capture. No causal correlation to an individual broadcast is invented. Storage failures are isolated from the response, and the normal retention/pruning policy applies.

### Compare broadcasts and copy debugging prompts

Open a submission and choose **Compare**. Select another of the latest 100 retained submissions, or enter its Current observation ID. The comparison shows event/connection/outcome/error differences, approved channel patterns/types, and changes in fully captured Payload, Metadata and Exact channels snapshots. Added/removed keys, null values and scalar type changes are distinct. Missing, limited or failed snapshots are explicitly unavailable, not treated as empty data. Redacted values cannot prove equality of original data. Arrays compare by position, so channel order alone may produce a difference. At most 200 differences and 12 levels are compared; individual displayed values are capped at 2,000 bytes. Comparison pages do not auto-refresh, and both records must still be retained and authorized.

**Debug with AI** opens the same local preview/copy modal used by jobs, for accepted, failed or unfinished broadcasts. It includes the retained observation, sanitized failure/stack, linked retained job and attempt timing, and snapshot values with capture states. Sections are bounded to 12,000 bytes. The prompt treats diagnostic content as untrusted and distinguishes submission acceptance from client receipt. Review before copying; nothing is sent to an AI service automatically. Missing payloads or old snapshots are not reconstructed.

### Webhook and broadcast dashboards

The top Webhooks and Broadcasts tabs now open `/current/webhooks/dashboard` and `/current/broadcasts/dashboard`. Existing list/detail URLs remain valid. Each sidebar starts with Dashboard, followed by its investigation pages. No additional migration or capture setting is needed for these dashboards; they summarize retained observations from the existing collectors.

Choose Last hour (5-minute buckets), Last 24 hours (hourly buckets, default), or Last 7 days (6-hour buckets). Dashboards refresh every five seconds using the existing pause/visibility/expanded-detail rules. Volume/failure charts and average-duration charts have expandable data tables. Missing duration values are excluded from averages. Drill-down links carry the displayed UTC time window into lists.

Webhook totals count HTTP receipts and outgoing attempts, including repeats. Failure counts include HTTP errors, captured handler/transport exceptions and incoming invalid reported signatures; downstream job failures are separate. Top providers/destinations and recent failures lead to investigation. Explicit repeated-event/delivery groups show counts within the period; their details may show additional retained history.

Broadcast totals separate submission failures from HTTP authorization failures. Average duration is the observed broadcaster call, not client latency. Event/route rankings use retained history across the period. Channel-pattern rankings deliberately sample the latest 1,000 submissions in the period, count each pattern once per submission, and label that sample; one submission can match several patterns. At most ten ranked labels and five recent failures/groups are displayed per section.

Capture status and coverage appear at the bottom. Disabled capture can still have retained history; zero observations do not establish inactivity or health. Storage failures display Unavailable instead of zero. Client receipt, subscriber counts and Reverb server health are not inferred. High-volume database performance and non-SQLite aggregate behavior have not been established by these checks.


## Renaming an earlier prototype installation

The package now uses `gatienorti/current`, the `Current\` PHP namespace, `Current\CurrentServiceProvider`, `current.*` route/view/config names, and `CURRENT_*` environment settings. The existing `Current\Current::step` API stays unchanged.

For an installation using the previous Composer package name, remove that requirement with `composer remove horizon-lens/horizon-lens --no-update`, add the GitHub VCS repository above, and require `gatienorti/current:dev-main`. Update explicit provider imports and capture calls to the Current namespace. Move any published `config/horizon-lens.php` settings into `config/current.php` and rename `HORIZON_LENS_*` environment settings to `CURRENT_*`. Review your configured history connection before restarting workers.

Rebuild Composer autoloading and Laravel config/route/view caches through your deployment process. Restart long-lived workers so queued application code uses the new package. The original database migration filename and `lens_*` SQL tables are intentionally preserved as storage identifiers; no history rewrite is required. A small old-provider entry point lets existing Composer discovery boot during this transition. Old names stored in historical observations and Git history are not rewritten.
