# Current

Current is a free, open-source Laravel observability companion, starting with Horizon jobs and designed to later include webhooks and broadcasts. Name availability has not been checked; this is not an official Laravel product.

Help developers find the right job and understand its failure through combined filters, execution-attempt history, recorded execution steps, and captured exception locations.

Status: preparing the first beta; not yet published. Current includes job filtering, execution maps, failure investigation, retries and coding-agent prompts. Installation and integration checks have run in isolated environments, including ADAPT and Vehify-backed labs. Production readiness and performance/usability improvements are not established; see the tested compatibility matrix and installation guide.

The current lab reuses Vehify’s Laravel 12.69.3 and Horizon 5.47.2 dependencies without modifying its checkout. Jobs, Webhooks, and Broadcasts are top-level tabs. The Jobs entry opens Dashboard; Monitoring replaces the former Job explorer. Dashboard reads Horizon’s overview metrics, workload/worker allocations, and supervisor records, with retained Current history labeled separately. Dashboard, job lists, job details, Metrics and Batches refresh in the background every five seconds by default, with a pause/resume control. Filter inputs remain intact; polling pauses in hidden tabs and while inspecting expanded details. Metrics has separate Jobs and Queues tabs. The Jobs sidebar is a flat list: Dashboard, Monitoring, Metrics, Batches, Pending, Completed, Silenced, Failed; Monitoring and job-state lists share a compact Queue / Job class / Observed time row with Apply and Reset. Filter menus follow the active theme, with searchable choices and full class names shown beneath short labels. More filters expands for job ID and tags, plus Status on Monitoring and exception class when filtering failures. One range picker provides custom dates/times and 5/15/30-minute, hour, 12-hour, day, and 7-day presets. Silenced classification is captured prospectively, with earlier history explicitly unknown; webhook/broadcast data collection remains planned. Violet is currently the only active accent, with the color selector hidden. The other palettes remain in the code for later. Light, Dark and System appearance remain selectable and remembered; status colors keep their meaning. A shared outline SVG set keeps navigation, controls and investigation icons consistent. Open the running [local preview](http://127.0.0.1:8097/horizon-lens), or follow setup and testing instructions. Explicit instrumentation captures runtime steps; the automatic source outline shows possible calls separately. Unchanged source outlines reuse a bounded per-worker cache; file-content changes invalidate it automatically. Complete automatic step discovery remains an experiment. Existing extensions are references and reuse candidates.

Failed-job details now offer **Retry job**, which opens Horizon’s new retry record in a one-second live view. **Follow live** centers the latest recorded step without shrinking the cards; manual pan/zoom disables following and preserves your viewport. Finished jobs return to five-second refresh. Only instrumented steps are visible, and steps shorter than the polling interval may appear already completed. Retry history links retained originals and retries, including native Horizon retries. Missing retry Args/Metadata can display an earlier retained snapshot labeled **Earlier enqueue**, with its source job and capture time. These historical values are never presented as a fresh capture. Set `CURRENT_ALLOW_RETRIES=false` to disable retry actions.

Failed-job details also offer **Debug with AI**: preview a locally generated debugging prompt in a modal, then use the clipboard button to copy it into your coding agent. It includes captured failure evidence and clearly labeled snapshot origins. Nothing is sent automatically.

## Brand

The Current mark uses two flowing paths to form an open C, paired with a lowercase wordmark. In the app it follows the selected accent and appearance. Reusable vector assets: [mark](resources/brand/current-mark.svg), [light wordmark](resources/brand/current-wordmark.svg), [dark wordmark](resources/brand/current-wordmark-dark.svg).

New installations use `/current`, `config/current.php`, `current:install`, `current:prune`, and `Current\Current::step`. Legacy integrations and table/route names remain compatible. The unpublished Composer identifier remains `horizon-lens/horizon-lens` pending distribution naming. See [installation and optional instrumentation](docs/INSTALLATION.md).

## Start here

Declared compatibility: Laravel **11.44+, 12.x, and 13.x**, Horizon **5.30+ within 5.x**, PHP **8.2+ within 8.x** (Laravel 13 requires 8.3+). Compatible pairs, tested profiles, upstream constraints, and runtime limits are documented in [Compatibility](docs/COMPATIBILITY.md). Package support is no longer tied to Vehify's patch versions.

- [Installation](docs/INSTALLATION.md): one-command setup, access, retention and optional steps.
- Plan: scope, order of work, and evidence gates.
- Architecture: proposed boundaries and reliability constraints.
- Research: source-backed observations and runtime evidence.
- Development: local preview, tests, and direct-integration limits.
- ADAPT integration: actual ADAPT application boot, job tests, and the separate [ADAPT preview](http://127.0.0.1:8098/__lens-lab/login).
- [Contributor instructions](AGENTS.md): how to continue without expanding scope prematurely.

## Intended experience

Job details include a policy/queue overview, observed lifecycle and attempt/worker history, plus expandable/copyable Args and Metadata JSON snapshots. New object dispatches automatically capture bounded, redacted job properties and standard metadata. Optional `currentArguments()` / `currentMetadata()` methods or a configured callback override these fields. Encrypted/unsupported payloads are unavailable; older history is not backfilled. The full-card execution map supports drag, wheel/button zoom, Fit View, 100% and a minimap; viewport state survives background data refresh. See the capture API and long-job demo.

The current investigation view places attempt selection above a compact full-width execution map, with expandable step details and persistent failure/attempt context below. Connected nodes show captured steps in recorded start order, with the failed node highlighted; arrows do not establish dependencies. Opening a job preserves its list filters and pagination when returning. A completed redelivery stays Completed while an earlier missing finish is shown separately; long-running attempts stay Processing with an uncertainty notice. History filters are independent of outcome. Source possibilities remain separate from recorded steps, and internal steps still require instrumentation.

Install a Composer package alongside Horizon, combine filters, and click any job—successful, failed, or in progress—to inspect its steps and attempts. On failure, connect the captured error and stack trace to the matching step and source location. Show observed execution separately from possible source paths; mark unavailable history and unresolved locations explicitly. How much can be discovered automatically, and where explicit step instrumentation is needed, remains to be evaluated.

Horizon continues to execute and manage jobs. Current adds visibility and a protected failed-job Retry action delegated to Horizon. A successful first release makes a real job investigation easier; commercial demand is not a prerequisite.

Future possibilities include webhook-to-job correlation, application broadcast inspection, and optional live dashboard updates through existing Laravel broadcasting infrastructure. These are separate extensions, not first-release requirements.
