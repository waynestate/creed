---
title: Keeping the patches fresh
description: How bin/refresh-patches.php re-reads the upstream feeds and rewrites the latest_patch fields in policy/policy.json, with the check-only CI job that watches it.
---

# Keeping the patches fresh

This page explains how the patch-level data in `policy/policy.json` stays tied to the upstream feeds, and what the check-only scheduled job does when the refresh changes something.

## What the refresh does

`bin/refresh-patches.php` re-reads the upstream feeds, rewrites the `latest_patch` fields in `policy/policy.json`, and proposes new Vue lines from endoflife.date. In doing so it updates `latest_patch` and `latest_patch_checked`.

## Where the data comes from

Patch levels are watched automatically. `bin/refresh-patches.php` reads php.net (PHP), Packagist (Laravel), nodejs.org (Node) and endoflife.date (a PHP cross-check and Vue lines).

## The check-only scheduled job

A scheduled CI job runs the refresh and is check-only: when the policy changed, the tool exits 3 and the job fails on the diff. A red scheduled run is a to-do, not an outage: run `php bin/refresh-patches.php`, read the diff, `composer ci`, add a CHANGELOG line, commit.

The committing path is the policy robot, covered in [The policy robot](policy-bot.md): it runs the fuller `bin/refresh-policy.php` every Monday, pushes the refresh to `main` when its `composer ci` gate is green, and opens a merge request only when the gate is red, so a red check-only run is usually fixed by the robot's next Monday push, or has a robot merge request waiting beside it. The robot's schedule (`CREED_JOB=policy-bot`) does not run this job.

## Support dates

This script never touches a support date. Dates move in two reviewed ways only: the [policy robot](policy-bot.md)'s Monday merge request, where `bin/refresh-policy.php` writes a date that moves earlier or a later `active_until`, and holds a later `security_until` until it is proven; or a person's own edit, with the upstream source in the commit message. End days are inclusive.

## Related

- [Refreshing the policy](policy-refresh.md)
- [The policy robot](policy-bot.md)
- [Reading the policy from PHP](reading-the-policy.md)
