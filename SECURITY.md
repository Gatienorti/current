# Security policy

Current is an early beta. Security fixes are currently targeted at the latest development revision; no stable release support window has been established.

## Reporting a vulnerability

Do not open a public issue containing exploit details, credentials, payloads, or customer data. Use GitHub's **Security → Report a vulnerability** when private vulnerability reporting is enabled on this repository.

If that option is unavailable, open an issue asking only for a private reporting channel, without including the vulnerability details. A response time is not guaranteed.

A useful private report includes affected versions, a minimal synthetic reproduction, impact, and any proposed mitigation. Do not test against systems you do not own or have permission to assess.

## Deployment considerations

Current exposes application-wide operational information. Restrict it to trusted operators. The default backend requires your application's Horizon authorization; the optional native backend requires an explicit `viewCurrent` gate. Native retry and enabled process controls additionally require `operateCurrent`. These are whole-application permissions, not tenant isolation. Review automatic Args capture and optional exporters before enabling them; redaction patterns do not detect all personal or confidential business data.

Use dedicated history storage, bounded retention, and the registered pruning schedule. Webhook and broadcast collection are off by default. Retry actions execute application jobs again through the selected backend and should be available only to operators allowed to perform them. Native maintenance commands require trusted CLI access; queue clearing and failed-payload removal change application work and cannot undo external effects already performed by a job.
