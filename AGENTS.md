# Current development

Read README.md and the installation and compatibility guides before project work. Read internal planning and research notes only when present locally; they are intentionally not committed. This is a separate Laravel project; AiGo plans and its development skill do not apply.

Current status: isolated local prototypes are authorized and implemented. Vehify's installed dependencies and mocked actual job are used read-only in the original lab. The user authorized actual ADAPT application testing; a separate lab boots its normal providers from a read-only mount with isolated storage, real Horizon workers and mocked external services. See docs/ADAPT-INTEGRATION.md. Broader Horizon compatibility is authorized; declared ranges and matrix evidence are in docs/COMPATIBILITY.md. Follow the user's authorized task; a future checklist is not authorization to implement the whole roadmap. In-place host installation and later integrations remain separate work.

- Prioritize combined filters and useful investigation before source graphs or additional operation types.
- Keep Horizon responsible for execution. Isolate version-dependent integration and verify supported versions before relying on contracts.
- Label findings VERIFIED, INFERRED, or UNVALIDATED where evidence matters. Never invent usability, correctness, or performance improvements.
- Keep static source possibilities separate from runtime observations. Missing telemetry is not success; an exception location is not necessarily root cause.
- Use existing PHP/Laravel tooling and minimal dependencies. Do not create a Go service or a framework abstraction without a real requirement.
- Protect dashboard access, redact before persistence, bound retention, and keep monitoring failures from breaking jobs.
- Use realistic integration tests for retries, releases, worker loss, and concurrency. Documentation changes need consistency checks, not application tests.
- Benchmark only for an explicitly scoped evaluation; never after every change. No paid model evaluation is needed for this project by default.
- Work in small, reviewable changes. Once a Git repository exists, follow the user's commit instructions; do not publish remotely without authorization.
- Update the existing plan with concrete completed work, checks, limits, and the next task. Do not maintain competing roadmaps.
- Webhooks and WebSockets remain later options. Separate visibility from delivery engines, and live dashboard transport from application-message inspection.

Keep screenshots, benchmarks, verification reports, experiments and internal planning/research documents local and ignored. Do not commit or upload them to GitHub.
