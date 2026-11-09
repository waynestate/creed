---
title: "CREED Handbook: 11 Secrets and debugging"
description: "Chapter 11 of the CREED handbook, the written rules for the Wayne State app stack: Secrets and debugging."
url: https://creed.apps.wayne.edu/handbook/secrets.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 11

# Secrets and debugging

3 sections · 9 provisions · **MUST NOT** 4 · **MUST** 3 · **SHOULD NOT** 1

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

## [§11.1](https://creed.apps.wayne.edu/handbook/secrets.html#credentials) Credentials

- Credentials (database and service passwords, API keys, tokens) **MUST NOT** be committed to version control, because history keeps them forever. Commit `.env.example` with placeholders instead. A credential that was ever committed is treated as exposed and rotated (WSU developer standards, 2024). [PROCTOR](https://proctor.apps.wayne.edu/) checks: `app-key` (a committed `.env` or `APP_KEY`) and `repo-url-credentials` (a token in a package source URL). The general secret scan is [Preflight](https://preflight.apps.wayne.edu/)'s `secret-detection` component (0.4.0), which runs Gitleaks over the tree and fails the pipeline on a match.
- Credentials and sensitive data **MUST** live outside any folder the web server serves. Laravel's `public/` is the only served folder; raw PHP apps serve one `public/` folder the same way (WSU developer standards, 2024). PROCTOR check: `served-folder` (dumps, backups, logs and env files in `public/`).
- The Laravel `APP_KEY` is a credential: whoever holds it can forge encrypted payloads, which can mean code execution. It **MUST NOT** be committed, a key that leaked **MUST** be rotated with `php artisan key:generate` on every environment that used it, and the session driver **SHOULD NOT** be `cookie`, which seals every session with the key (GitGuardian, exploiting public APP_KEY leaks). PROCTOR check: `app-key`.
- An app uses its own dedicated application ID, never a person's employee or student AccessID, to reach another server or service, with the fewest permissions the task needs (WSU developer standards, 2024).
- Real values come from the environment at deploy time (the Citadel dashboard or CI variables), never from a file in the repo (WSU developer standards, 2024).

## [§11.2](https://creed.apps.wayne.edu/handbook/secrets.html#errors-and-debug-output) Errors and debug output

- `APP_DEBUG` **MUST** be `false` in production (WSU developer standards, 2024). The Laravel adapter writes one warning `helmet.debug_enabled_in_production` when it is true in the production environment. A PROCTOR check for this is proposed, not built.
- Public-facing code **MUST NOT** display default interpreter or framework error pages to the user; show a controlled message and log the detail (WSU developer standards, 2024). No check exists yet.
- Logs and diagnostic output **MUST NOT** contain credentials or personally identifiable information such as Social Security numbers. That includes logged exceptions (WSU developer standards, 2024). No check exists yet.

A leaked password is a bad encore: everyone remembers it, and nobody asked for a second showing.

## [§11.3](https://creed.apps.wayne.edu/handbook/secrets.html#not-decided-here) Not decided here

- The [HELMET](https://helmet.apps.wayne.edu/) header rules and runtime refusals are written in `runtime-and-headers.md`.

Set from [`handbook/secrets.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/secrets.md), impression `8f59128`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
