---
title: Policy file format
description: The shape of policy/policy.json: the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against, guarded by a schema.
---

# Policy file format

This page shows the shape of `policy/policy.json` and `policy/sources.json`, exactly as their schemas state it.

CREED is the Wayne State app stack's version policy, as data. One file, `policy/policy.json`, lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against. The schema titles the file "CREED version policy", requires `schema_version`, `components` and `rules`, and allows no top-level properties beyond the seven in the table below.

## Top level of policy/policy.json

| Name | Type | What the schema states |
|---|---|---|
| `schema_version` | integer | Must be exactly `1`. Required. |
| `refreshed_on` | string | A date in `YYYY-MM-DD` form. Optional. |
| `components` | object | At least one entry. Each entry is a component holding a `versions` object, and nothing else. Required. |
| `tools` | object | Maps tool names to a minimum version each, and optionally a release cooldown. Optional. |
| `sessions` | object | Records the session lifetimes the stack sets elsewhere: SESAME in minutes (`sesame.idle_minutes`, `sesame.absolute_minutes`) and the Drawbridge gateway in seconds (`gateway.idle_seconds`, `gateway.session_seconds`), each entry carrying a `source` that names the file in that repo where the default is set. Optional. |
| `rules` | object | The rules every check runs against. Required. |
| `gate` | object | The deploy-gate stage of each PROCTOR check, with the date each stage starts. Optional. |

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

`tools` is optional. Its property names must match `^[a-z][a-z0-9-]*$`, and each value is an object with one required field and one optional one:

| Name | Type | What the schema states |
|---|---|---|
| `minimum` | string | Matches `^\d+\.\d+\.\d+$`, a major.minor.patch version. Required inside each tool. |
| `cooldown` | object | Optional. `period` (required) is a duration such as `3 days` (a whole number, then hour, day or week, singular or plural); `ignore` is an optional list of package patterns that skip the wait. The bundled policy sets Composer's to `3 days`, ignoring `waynestate/*`. |

## rules

`rules` allows six properties and no others. Three are required; the other three arrived later, so a policy written
before them still validates, and the reader answers the stack's default for a missing one.

| Name | Type | What the schema states |
|---|---|---|
| `audit_severity_threshold` | string | Required. The bundled policy says `high`. |
| `banned_constraints` | array of strings | Required. The bundled policy bans `dev-*` and `*`. |
| `eol_grace_days` | integer | Minimum `0`. Required. The bundled policy says 90. |
| `abandoned_packages` | string | `ignore`, `report` or `fail`: Composer's own `audit.abandoned` setting. Optional; `abandonedPackages()` answers `fail` without it, Composer's default since 2.7. |
| `override_max_days` | integer | Minimum `1`: the longest an override may run, in days past the day PROCTOR runs. Optional; `overrideMaxDays()` answers 90 without it. |
| `override_self_approval` | string | `warn` or `fail`: what PROCTOR does when an override's `approved_by` names its own author, or the author cannot be read. Optional; `overrideSelfApproval()` answers `warn` without it. |

## gate

`gate` is optional. It stages PROCTOR's checks in the deploy gate, so a new check can report first, warn next and
block last, each from a stated date. `gateStage($checkId, $on)` reads it.

| Name | Type | What the schema states |
|---|---|---|
| `default_stage` | string | `report`, `warn` or `block`: the stage of a check with no entry in `stages` that applies yet. Optional; `block` without it, so a policy shaped before stages existed keeps today's gate. |
| `stages` | object | Maps a check ID (`^[a-z0-9-]+$`) to a list of at least one `{"stage", "from"}` entry, both required; `from` is a `YYYY-MM-DD` date. The entry with the latest `from` on or before the run date wins. Optional. |

The bundled policy sets `default_stage` to `block` and stages no check yet.

## sessions

`sessions` is optional. It records the session lifetimes other parts of the stack set, so the auth chapter can point
at one place; nothing in CREED enforces them.

| Name | Type | What the schema states |
|---|---|---|
| `sesame` | object | `idle_minutes` and `absolute_minutes` (integers, minimum `1`) and `source` (the file in SESAME that sets the default), all required. The bundled policy says 120 and 720. |
| `gateway` | object | `idle_seconds` and `session_seconds` (integers, minimum `1`) and `source` (the file in Drawbridge that sets the default), all required. The bundled policy says 3600 and 28800. |

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
