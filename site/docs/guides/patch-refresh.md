---
title: "Keeping the patches fresh | CREED docs"
description: "How bin/refresh-patches.php re-reads the upstream feeds and rewrites the latest_patch fields in policy/policy.json, with the check-only CI job that watches it."
url: https://creed.apps.wayne.edu/docs/guides/patch-refresh.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Keeping the patches fresh[#](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html#keeping-the-patches-fresh)

This page explains how the patch-level data in `policy/policy.json` stays tied to the upstream feeds, and what the check-only scheduled job does when the refresh changes something.

## What the refresh does[#](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html#what-the-refresh-does)

`bin/refresh-patches.php` re-reads the upstream feeds, rewrites the `latest_patch` fields in `policy/policy.json`, and proposes new Vue lines from endoflife.date. In doing so it updates `latest_patch` and `latest_patch_checked`.

## Where the data comes from[#](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html#where-the-data-comes-from)

Patch levels are watched automatically. `bin/refresh-patches.php` reads php.net (PHP), Packagist (Laravel), nodejs.org (Node) and endoflife.date (a PHP cross-check and Vue lines).

## The check-only scheduled job[#](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html#the-check-only-scheduled-job)

A scheduled CI job runs the refresh and is check-only: when the policy changed, the tool exits 3 and the job fails on the diff. A red scheduled run is a to-do, not an outage: run `php bin/refresh-patches.php`, read the diff, `composer ci`, add a CHANGELOG line, commit.

The committing path for the same refresh is the policy robot, covered in [The policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html).

## Support dates stay hand-edited[#](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html#support-dates-stay-hand-edited)

Support dates stay hand-edited: a person reviews each support-date edit. `active_until` and `security_until` for existing lines change only when a person edits them, with the upstream source in the commit message. End days are inclusive.

## Related[#](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html#related)

- [Refreshing the policy](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html)
- [The policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html)
- [Reading the policy from PHP](https://creed.apps.wayne.edu/docs/guides/reading-the-policy.html)
