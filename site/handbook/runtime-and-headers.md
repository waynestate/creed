---
title: "CREED Handbook: 6 Runtime and headers"
description: "Chapter 6 of the CREED handbook, the written rules for the Wayne State app stack: Runtime and headers."
url: https://creed.apps.wayne.edu/handbook/runtime-and-headers.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 6

# Runtime and headers

8 sections · 16 provisions · **MUST NOT** 1 · **MUST** 2 · **SHOULD NOT** 1

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

## [§6.1](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#enforcement) Enforcement

- The runtime (Baseline) is enforcement point (a): it warns, and refuses only for unsafe config (the stack's enforcement design).

## [§6.2](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#headers) Headers

- Every response carries the same headers from [HELMET](https://helmet.apps.wayne.edu/) (the stack's runtime and headers design):
  - Content-Security-Policy: the [WayneUI](https://wayneui.apps.wayne.edu/) default policy plus a per-request nonce and `strict-dynamic`, with `frame-ancestors 'self'`, `base-uri 'self'`, `object-src 'none'` and `form-action 'self'` added.
  - X-Content-Type-Options `nosniff`.
  - X-Frame-Options `SAMEORIGIN`.
  - Referrer-Policy `strict-origin-when-cross-origin`.
  - Permissions-Policy, with camera, geolocation, microphone, payment and usb denied by default.
  - Cross-Origin-Opener-Policy `same-origin`.
  - Strict-Transport-Security `max-age=31536000; includeSubDomains`, on HTTPS responses only.
- Cross-Origin-Resource-Policy is off by default and opt-in, because a blanket `same-site` can break an app whose assets are read cross-origin (the stack's runtime and headers design). HELMET 1.0 ships that default: `corp` (raw PHP) or `HELMET_CORP` (Laravel) set to `same-site`, `same-origin` or `cross-origin` sends it.
- An app **SHOULD NOT** weaken the default Content-Security-Policy with `'unsafe-inline'` for scripts; it uses the nonce instead (the stack's runtime and headers design). No check exists yet.

The headers are the setlist: the same one on every response. The HSTS encore plays only when the show is really on HTTPS.

## [§6.3](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#proxy-trust) Proxy trust

- Behind a proxy the app sees the proxy's address, so `X-Forwarded-Proto` is honored only from a trusted proxy (the stack's runtime and headers design).
- A platform preset (`citadel` or `docker`) names the trusted proxies; off the platform the app passes its own list. An unknown preset throws at boot (the stack's runtime and headers design).
- No Strict-Transport-Security is sent for a request that was not really secure (the stack's runtime and headers design).

## [§6.4](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#sessions) Sessions

- HELMET hardens the PHP session settings before the session starts (the stack's runtime and headers design).
- The request-forgery guard (Sec-Fetch-Site, then Origin) is optional and off by default; a rejected request gets 403 `Forbidden` (the stack's runtime and headers design).

## [§6.5](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#health) Health

- `/up` is the health endpoint HELMET answers for raw PHP apps (the stack's runtime and headers design).

## [§6.6](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#metrics) Metrics

- HELMET's metrics endpoint speaks the Prometheus text format and is off by default. It stays off until a Prometheus server scrapes the app; then the app turns it on with `HELMET_METRICS_ENABLED=true` (the stack's metrics decision). HELMET: with neither `HELMET_METRICS_TOKEN` nor `HELMET_METRICS_ALLOW` set, the endpoint answers 404 and logs a warning.
- The scrape token **MUST NOT** be committed. On Citadel it goes in the app's `.env` through Citadel's configuration files step, which writes the file at deploy time (the stack's metrics decision). [PROCTOR](https://proctor.apps.wayne.edu/) check: `app-key` (`proctor/app-key/committed-env` flags a committed `.env`).
- On Citadel, request counts use the `sqlite` store for now (`HELMET_METRICS_STORAGE=sqlite`, with `HELMET_METRICS_SQLITE_PATH` on a file in the app's writable storage); the final store is picked when the stack's Prometheus server exists (the stack's metrics decision). Documented only.

## [§6.7](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#errors-in-production) Errors in production

- PHP `display_errors` **MUST** be `Off` and `log_errors` `On` in production ([Squire](https://squire.apps.wayne.edu/)'s deploy kit ships that php.ini; HELMET neither sets nor checks it). No check exists yet.
- Laravel `APP_DEBUG` **MUST** be `false` in production (WSU developer standards, 2024). The Laravel adapter writes one warning `helmet.debug_enabled_in_production` when it is true in the production environment. PROCTOR check: `app-debug`, on PROCTOR main: it warns when a committed production env template, deploy file or CI pipeline sets `APP_DEBUG` to true (`.env.example` is the local template and is skipped).

## [§6.8](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#not-decided-here) Not decided here

- The Content-Security-Policy report endpoints.

Set from [`handbook/runtime-and-headers.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/runtime-and-headers.md), impression `fd7d250`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
