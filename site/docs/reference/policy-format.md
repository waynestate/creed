---
title: "Policy file format | CREED docs"
description: "The shape of policy/policy.json: the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against, guarded by a schema."
url: https://creed.apps.wayne.edu/docs/reference/policy-format.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Policy file format[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#policy-file-format)

This page shows the shape of `policy/policy.json` and `policy/sources.json`, exactly as their schemas state it.

CREED is the Wayne State app stack's version policy, as data. One file, `policy/policy.json`, lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against. The schema titles the file "CREED version policy", requires `schema_version`, `components` and `rules`, and allows no other top-level properties.

## Top level of policy/policy.json[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#top-level-of-policy-policy-json)

| Name | Type | What the schema states |
| --- | --- | --- |
| `schema_version` | integer | Must be exactly `1`. Required. |
| `refreshed_on` | string | A date in `YYYY-MM-DD` form. Optional. |
| `components` | object | At least one entry. Each entry is a component holding a `versions` object, and nothing else. Required. |
| `tools` | object | Maps tool names to a minimum version each. Optional. |
| `rules` | object | The rules every check runs against. Required. |

## The line fields[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#the-line-fields)

Each component holds a `versions` object that maps a version to a line. Every line may carry these fields:

| Name | Type | What the schema states |
| --- | --- | --- |
| `released` | string | A date. Optional. |
| `active_until` | string | A date. Optional. |
| `security_until` | string | A date. Required on every line except one marked `"current": true`, where it must be absent. |
| `latest_patch` | string | Any string. Optional. |
| `latest_patch_checked` | string | A date. Optional. |
| `current` | boolean | Marks the line as current. Optional. |
| `images` | object | May hold `citadel`, `docker`, `openshift` and `coolify`, each a non-empty string. Optional. |

The `current` rule is the only conditional in the schema: a line with `"current": true` must not carry `security_until`, and every other line must.

## tools[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#tools)

`tools` is optional. Its property names must match `^[a-z][a-z0-9-]*$`, and each value is an object with one required field:

| Name | Type | What the schema states |
| --- | --- | --- |
| `minimum` | string | Matches `^\d+\.\d+\.\d+$`, a major.minor.patch version. Required inside each tool. |

## rules[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#rules)

`rules` allows exactly three properties, all required:

| Name | Type | What the schema states |
| --- | --- | --- |
| `audit_severity_threshold` | string | Required. |
| `banned_constraints` | array of strings | Required. |
| `eol_grace_days` | integer | Minimum `0`. Required. |

## policy/sources.json[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#policy-sources-json)

The schema titles `policy/sources.json` the "CREED refresh provenance ledger". It records, per observed value, which source said it and the first and last day it was seen.

| Name | Type | What the schema states |
| --- | --- | --- |
| `schema_version` | integer | Must be exactly `1`. Required. |
| `updated_on` | string | A date in `YYYY-MM-DD` form. Optional. |
| `observations` | object | Nested objects, down to one record per observed value. Required. |

Each leaf record carries exactly three fields:

| Name | Type | What the schema states |
| --- | --- | --- |
| `value` | string | Required. |
| `first_seen` | string | A date in `YYYY-MM-DD` form. Required. |
| `last_seen` | string | A date in `YYYY-MM-DD` form. Required. |

## Schema version and older readers[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#schema-version-and-older-readers)

The schema is `version: 1`. Optional new fields keep it at 1; a breaking schema change needs a new version and a release of every reader. The schema is strict: unknown keys fail CREED's own CI. Readers are tolerant: unknown keys are ignored, so older readers keep working on a newer file. The older-reader contract is pinned by `tests/OlderReaderContractTest.php`; change the fixture only with a `schema_version` bump.

## A real excerpt[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#a-real-excerpt)

One real line from `policy/policy.json`, verbatim (the PHP 8.2 entry):

```json
"8.2": {
    "active_until": "2024-12-31",
    "security_until": "2026-12-31",
    "latest_patch": "8.2.35",
    "latest_patch_checked": "2026-10-28"
},
```

Note

Creed Bratton's past is a mystery. Ours is in `policy/policy.json`, where it belongs.

## Related[#](https://creed.apps.wayne.edu/docs/reference/policy-format.html#related)

- [Commands and exit codes](https://creed.apps.wayne.edu/docs/reference/commands.html)
- [Configuration](https://creed.apps.wayne.edu/docs/reference/configuration.html)
- [Reader API](https://creed.apps.wayne.edu/docs/reference/api.html)
- [Support states and the grace period](https://creed.apps.wayne.edu/docs/guides/support-states.html)
- [Refreshing the policy](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html)
