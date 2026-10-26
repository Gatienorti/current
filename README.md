<p align="center">
  <img src="resources/brand/current-wordmark.svg" alt="Current" width="220">
</p>

# See what happened inside your Laravel jobs

Current brings job investigation, webhook activity, and broadcast debugging into your Laravel application. Find a job, inspect its attempts, follow recorded steps, and see where an exception surfaced—all alongside Horizon.

**Early beta · MIT licensed · Self-hosted · No frontend build**




## What you can investigate

| Area | Included |
| --- | --- |
| **Queues & Jobs** | Horizon dashboard, supervisor/queue architecture, combined job filters, metrics, batches, and registered schedules. |
| **Job details** | Attempts and retries, pan/zoom execution maps, exception locations and stack context, Args and Metadata, and related webhook/broadcast activity. |
| **Webhooks** | Incoming receipts and outgoing Laravel HTTP deliveries, HTTP outcomes, explicit retry grouping, optional context snapshots, and linked jobs. |
| **Broadcasts** | Reverb and Pusher submissions, queued/immediate timelines, authorization failures, snapshot comparison, and linked jobs. |

Retry failed jobs through Horizon. Copy a **Debug with AI** prompt from captured failure evidence into your coding agent; Current sends nothing to an AI service automatically.

Light, dark, and system appearance are included. Live data refreshes every five seconds by default, with pause and interval controls.


## Get started

Current is preparing its first public beta and is **not on Packagist yet**. Install the development branch from GitHub in a development application, following the [installation guide](docs/INSTALLATION.md):

```sh
composer config repositories.current vcs https://github.com/Gatienorti/current
composer require horizon-lens/horizon-lens:dev-main
php artisan current:install
```

Open **`/current`** using your application's existing Horizon access. The installer publishes configuration, creates a dedicated SQLite history store, and runs Current's migrations. Restart long-lived workers after installation and keep Laravel's scheduler running for pruning.

The Composer identifier still uses the development name; the dashboard and public API are branded Current. No hosted account, Node build, or WebSocket server is required for jobs.

**Requirements:** PHP 8.2+, Laravel 11.44/12/13, and Horizon 5.30+ within 5.x, subject to compatible version pairs. Laravel 13 requires PHP 8.3+. See the [compatibility matrix](docs/COMPATIBILITY.md) for tested combinations and database limits.

## Give a job an execution map

Queue lifecycle and attempt observations work without adding step calls. To see what happens *inside* a job, wrap useful operations:

```php
use Current\Current;

public function handle(): void
{
    Current::step('Validate order', fn () => $this->validateOrder());
    Current::step('Reserve inventory', fn () => $this->reserveInventory());
    Current::step('Charge payment', fn () => $this->chargePayment());
}
```

The callback keeps its normal return value and exception behavior. Current records each named step's timing and outcome. Arrows represent recorded start order, not inferred dependencies.

New supported object dispatches capture bounded, redacted job properties and standard metadata. You can disable automatic snapshots or define explicit `currentArguments()` and `currentMetadata()` exports. See [capture configuration](docs/INSTALLATION.md#what-appears-automatically).

## Follow activity across your application

Webhook and broadcast collection are **opt-in**, with explicit route, destination, event, and channel allowlists. Payload exports require additional configuration. Current observes your existing integrations; it does not replace webhook signature validation, delivery engines, or broadcaster authorization.

[Set up incoming and outgoing webhooks](docs/INSTALLATION.md#4-enable-optional-integrations) · [Set up Reverb or Pusher](docs/INSTALLATION.md#optional-reverb-broadcast-observation)

## Know what the evidence means

- History starts when collection is enabled; there is no historical backfill. Retention defaults to seven days.
- Missing telemetry is unknown. Worker completion and HTTP success do not prove downstream business success or client receipt.
- Queue architecture uses configuration and bounded static inspection. Dynamic routing can remain unresolved.
- Schedule lists describe registered tasks and expression-based next times, not scheduler execution health.
- Redaction happens before persistence, but cannot identify every sensitive business field. Review your capture policy and dashboard access.

Current remains an early beta. Isolated integration and compatibility tests have passed; production load and broad real-world usage are not yet established. Current is an independent project, not an official Laravel product.

## Documentation and contributing

- [Installation and configuration](docs/INSTALLATION.md)
- [Compatibility and verification](docs/COMPATIBILITY.md)
- [Contributing and running tests](CONTRIBUTING.md)
- [Security reporting](SECURITY.md)
- Architecture, research, and development plan

Bug reports with a minimal, sanitized reproduction are especially useful. Keep credentials, real payloads, and customer information out of public issues.

Released under the [MIT license](LICENSE).
