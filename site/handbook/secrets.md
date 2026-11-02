---
title: "CREED Handbook: 11 Secrets and debugging"
description: "Chapter 11 of the CREED handbook, the written rules for the Wayne State app stack: Secrets and debugging."
url: https://creed.apps.wayne.edu/handbook/secrets.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 11

# Secrets and debugging

3 sections · 8 provisions · **MUST NOT** 3 · **MUST** 2

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

## [§11.1](https://creed.apps.wayne.edu/handbook/secrets.html#credentials) Credentials

- Credentials (database and service passwords, API keys, tokens) **MUST NOT** be committed to version control, because history keeps them forever. Commit `.env.example` with placeholders instead. A credential that was ever committed is treated as exposed and rotated (WSU developer standards, 2024).
- Credentials and sensitive data **MUST** live outside any folder the web server serves. Laravel's `public/` is the only served folder; raw PHP apps serve one `public/` folder the same way (WSU developer standards, 2024).
- An app uses its own dedicated application ID, never a person's employee or student AccessID, to reach another server or service, with the fewest permissions the task needs (WSU developer standards, 2024).
- Real values come from the environment at deploy time (the Citadel dashboard or CI variables), never from a file in the repo (WSU developer standards, 2024).

## [§11.2](https://creed.apps.wayne.edu/handbook/secrets.html#errors-and-debug-output) Errors and debug output

- `APP_DEBUG` **MUST** be `false` in production (WSU developer standards, 2024).
- Public-facing code **MUST NOT** display default interpreter or framework error pages to the user; show a controlled message and log the detail (WSU developer standards, 2024).
- Logs and diagnostic output **MUST NOT** contain credentials or personally identifiable information such as Social Security numbers. That includes logged exceptions (WSU developer standards, 2024).

A leaked password is a bad encore: everyone remembers it, and nobody asked for a second showing.

## [§11.3](https://creed.apps.wayne.edu/handbook/secrets.html#not-decided-here) Not decided here

- Which [HELMET](https://helmet.apps.wayne.edu/) header rules and runtime refusals apply is still to be decided. See `runtime-and-headers.md`.

Set from [`handbook/secrets.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/secrets.md), impression `51b4acc`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
