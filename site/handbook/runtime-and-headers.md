---
title: "CREED Handbook: 6 Runtime and headers"
description: "Chapter 6 of the CREED handbook, the written rules for the Wayne State app stack: Runtime and headers."
url: https://creed.apps.wayne.edu/handbook/runtime-and-headers.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 6

# Runtime and headers

7 sections · 15 provisions · **MUST** 2 · **SHOULD NOT** 1

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
- Cross-Origin-Resource-Policy is off by default (the stack's runtime and headers design).
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

## [§6.6](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#errors-in-production) Errors in production

- PHP `display_errors` **MUST** be `Off` and `log_errors` `On` in production ([Squire](https://squire.apps.wayne.edu/)'s deploy kit ships that php.ini; HELMET neither sets nor checks it). No check exists yet.
- Laravel `APP_DEBUG` **MUST** be `false` in production (WSU developer standards, 2024). The Laravel adapter writes one warning `helmet.debug_enabled_in_production` when it is true in the production environment.

A [PROCTOR](https://proctor.apps.wayne.edu/) check for this is proposed, not built.

## [§6.7](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html#not-decided-here) Not decided here

- The Cross-Origin-Resource-Policy default.
- The Content-Security-Policy report endpoints.
- The metrics endpoint.

Set from [`handbook/runtime-and-headers.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/runtime-and-headers.md), impression `48ebd53`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
