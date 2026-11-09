---
title: Policy file format
description: The shape of policy/policy.json: the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against, guarded by a schema.
---

# Policy file format

This page shows the shape of `policy/policy.json` and `policy/sources.json`, exactly as their schemas state it.

CREED is the Wayne State app stack's version policy, as data. One file, `policy/policy.json`, lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against. The schema titles the file "CREED version policy", requires `schema_version`, `components` and `rules`, and allows no other top-level properties.

## Top level of policy/policy.json

| Name | Type | What the schema states |
|---|---|---|
| `schema_version` | integer | Must be exactly `1`. Required. |
| `refreshed_on` | string | A date in `YYYY-MM-DD` form. Optional. |
| `components` | object | At least one entry. Each entry is a component holding a `versions` object, and nothing else. Required. |
| `tools` | object | Maps tool names to a minimum version each. Optional. |
| `sessions` | object | Records the session lifetimes the stack sets elsewhere: SESAME in minutes (`sesame.idle_minutes`, `sesame.absolute_minutes`) and the Drawbridge gateway in seconds (`gateway.idle_seconds`, `gateway.session_seconds`), each entry carrying a `source` that names the file in that repo where the default is set. Optional. |
| `rules` | object | The rules every check runs against. Required. |

## The line fields

Each component holds a `versions` object that maps a version to a line. Every line may carry these fields:

| Name | Type | What the schema states |
|---|---|---|
| `released` | string | A date. Optional. |
| `active_until` | string | A date. Optional. |
| `security_until` | string | A date. Required on every line except one marked `"current": true`, where it must be absent. |
| `latest_patch` | string | Any string. Optional. |
| `latest_patch_checked` | string | A date. Optional. |
| `current` | boolean | Marks the line as current. Optional. |
| `requires` | object | May hold `php` (an object with required `min` and `max`, each a version string matching `^\d+(\.\d+)*$`) and `mysql` and `mariadb` (each a version string matching the same pattern), and nothing else. Optional. The bundled policy fills it for the Laravel lines, from Laravel's release notes and database pages. |
| `images` | object | May hold `citadel`, `docker`, `openshift` and `coolify`, each a non-empty string. Optional. Today `docker` is filled for PHP 8.3 to 8.5; the other keys are not filled. |

The `current` rule is the only conditional in the schema: a line with `"current": true` must not carry `security_until`, and every other line must. A line marked `"current": false`, or with no `current` at all, therefore needs a `security_until`: CREED does not list a line before its dates are known. `bin/validate-policy.php` checks this rule before the schema and names the line and the fix, for example `php 9.9: the line has neither security_until nor "current": true; add security_until (...) or mark the line "current": true.` The rule matters at runtime too: the reader does not guess the state of an undated line, so `supported()` and `oldestSupported()` would throw for that whole component.

## tools

`tools` is optional. Its property names must match `^[a-z][a-z0-9-]*$`, and each value is an object with one required field:

| Name | Type | What the schema states |
|---|---|---|
| `minimum` | string | Matches `^\d+\.\d+\.\d+$`, a major.minor.patch version. Required inside each tool. |

## rules

`rules` allows exactly three properties, all required:

| Name | Type | What the schema states |
|---|---|---|
| `audit_severity_threshold` | string | Required. |
| `banned_constraints` | array of strings | Required. |
| `eol_grace_days` | integer | Minimum `0`. Required. |

## policy/sources.json

The schema titles `policy/sources.json` the "CREED refresh provenance ledger". It records, per observed value, which source said it and the first and last day it was seen.

| Name | Type | What the schema states |
|---|---|---|
| `schema_version` | integer | Must be exactly `1`. Required. |
| `updated_on` | string | A date in `YYYY-MM-DD` form. Optional. |
| `observations` | object | Nested objects, down to one record per observed value. Required. |

Each leaf record carries exactly three fields:

| Name | Type | What the schema states |
|---|---|---|
| `value` | string | Required. |
| `first_seen` | string | A date in `YYYY-MM-DD` form. Required. |
| `last_seen` | string | A date in `YYYY-MM-DD` form. Required. |

## Schema version and older readers

The schema is `version: 1`. Optional new fields keep it at 1; a breaking schema change needs a new version and a release of every reader. The schema is strict: unknown keys fail CREED's own CI. Readers are tolerant: unknown keys are ignored, so older readers keep working on a newer file. The older-reader contract is pinned by `tests/OlderReaderContractTest.php`; change the fixture only with a `schema_version` bump.

## A real excerpt

One real line from `policy/policy.json`, verbatim (the PHP 8.2 entry):

```json
"8.2": {
    "active_until": "2024-12-31",
    "security_until": "2026-12-31",
    "latest_patch": "8.2.35",
    "latest_patch_checked": "2026-10-28"
},
```

> [!NOTE]
> Creed Bratton's past is a mystery. Ours is in `policy/policy.json`, where it belongs.

## Related

- [Commands and exit codes](commands.md)
- [Configuration](configuration.md)
- [Reader API](api.md)
- [Support states and the grace period](../guides/support-states.md)
- [Refreshing the policy](../guides/policy-refresh.md)
