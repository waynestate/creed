---
title: "Refreshing the policy | CREED docs"
description: "How bin/refresh-policy.php keeps every support date, patch and new line tied to a canonical source, with its change classes and exit codes."
url: https://creed.apps.wayne.edu/docs/guides/policy-refresh.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Refreshing the policy[#](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html#refreshing-the-policy)

This page explains how `bin/refresh-policy.php` keeps every support date, patch and new line in `policy/policy.json` tied to a canonical source, so nobody types a date by hand.

## Sources per component[#](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html#sources-per-component)

PHP dates come from php.net, Node dates from the Node.js release schedule, and Laravel, MySQL, Vue and Composer from endoflife.date; patches come from php.net, nodejs.org, Packagist and npm.

## Change classes[#](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html#change-classes)

Each run sorts every change into a class:

| Class | What it is | What happens |
| --- | --- | --- |
| `tighten` | a date moves earlier, or a `current` line gets its first end date | written |
| `neutral` | a new `latest_patch`, or a new line above every listed line | written |
| `loosen-warning` | `active_until` moves later (a warning tier, never a failure) | written |
| `loosen-fail` | `security_until` moves later, or an EOL line would be supported again | held, unless two independent sources agree (php.net and endoflife.date for PHP, schedule.json and endoflife.date for Node) or `policy/sources.json` shows the same value from the same source for 14 days |
| `refused` | removing a line or a date, or any change to `tools` or `rules` | never automatic; reported (a Composer minimum on an EOL line shows up here) |

## When a source is down[#](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html#when-a-source-is-down)

A source that is down, slow or returns something unreadable changes nothing: its fields keep their last good value. A line missing from a feed is never removed. Where a first-party source and endoflife.date disagree, the first party wins and the disagreement is reported.

## Running the refresher[#](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html#running-the-refresher)

```
php bin/refresh-policy.php [--dry-run] [--offline <dir>] [--policy <path>]
    [--sources <path>] [--report <file>] [--today YYYY-MM-DD] [--touch]
```

`--report` writes JSON with `changes`, `held`, `refused`, `disagreements` and `source_errors`. `--touch` sets `refreshed_on` to today even when nothing else changed (the weekly heartbeat). `policy/sources.json` records, per value, which source said it, and the first and last day it was seen.

## Exit codes[#](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html#exit-codes)

Exit codes: `0` nothing worth committing (always `0` with `--dry-run`); `1` hard failure; `3` something was written to `policy/policy.json` or `policy/sources.json`, including a released `loosen-fail` (the policy robot raises the alert from the report); `4` something was held or refused. The refresher itself never commits: whatever it could write is written either way, and on exit 4 the held or refused items stay out of the file.

What happens next is the [policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html)'s job: on exit 3 it opens a merge request that merges itself when its pipeline passes; on exit 4 it opens one that waits for a person, plus an `auto-alert` issue per held or refused item.

`bin/refresh-patches.php` still works as before.

## Related[#](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html#related)

- [Keeping the patches fresh](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html)
- [The policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html)
- [Policy file format](https://creed.apps.wayne.edu/docs/reference/policy-format.html)
