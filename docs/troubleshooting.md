---
title: Troubleshooting
description: The failures that look alarming but are expected: a red refresh-patches run, the policy robot's red gate, fallback merge request and token, refresher exit codes, stale docs, and the versions the reader refuses.
---

# Troubleshooting

Answers to the failures that look alarming but are expected, each with the page that explains the behavior.

## Why did the scheduled `refresh-patches` job fail?

A scheduled `refresh-patches` failure is expected whenever upstream ships a patch. The scheduled job is check-only: when the policy changed, the tool exits 3 and the job fails on the diff. Treat the red run as a to-do, not an outage. On GitLab the policy robot's Monday run usually lands the same patch on `main` by itself (or, in a red week, carries it in its fallback merge request): check `main` first. Otherwise run `php bin/refresh-patches.php`, read the diff, run `composer ci`, add a CHANGELOG line, and commit. The [Changelog](changelog.md) is where that line goes.

## Why does the `policy-bot` job print "CREED_BOT_TOKEN not set; skipping the policy robot" and stay green?

That is the designed behavior when the masked `CREED_BOT_TOKEN` CI variable is not set: the job prints the line and stays green. Missing CI variables stop the run before any push, so nothing is committed. The job runs on its dedicated schedule (the one carrying `CREED_JOB=policy-bot`) and by hand from the web. It landed in 0.1.6; see the [Changelog](changelog.md).

## Why can the robot not push when `auto/policy-refresh` is protected?

The branch `auto/policy-refresh` must not be protected, or the robot cannot push it. It is the red-week fallback: when `composer ci` fails on the refresh, the robot force-pushes the commit there as a merge request labeled `auto-data`. A green week pushes to `main` with the job token instead, which needs "Allow Git push requests to the repository" (CI/CD > Job token permissions) and a schedule owner who may push to the protected `main`. Branch protection is a project setting, not a file in this repo; [The policy robot](guides/policy-bot.md) has the whole setup.

## Why did the robot open a merge request instead of pushing to `main`?

Because the gate went red, on purpose. The robot runs `composer ci` on its refresh in its own job, and only a green gate pushes straight to `main`. A red one force-pushes the commit to `auto/policy-refresh` as a merge request labeled `auto-data` and fails the job, so the schedule owner gets mail. The failure may have nothing to do with the policy (a fresh security advisory in `composer audit`, say). Read the failed job, fix the failure on `main`, then start the robot by hand on `main`: a green gate pushes the refresh itself, and the stale merge request can be closed. Held and refused items are in the body and each still has an `auto-alert` issue, red or green. Never commit to `auto/policy-refresh`: the next red run overwrites it. [Reviewing what it does](guides/policy-bot.md#reviewing-what-it-does) walks through it.

## Tests fail oddly on Windows after a Docker install

Delete `vendor/` and install again. The install and Docker paths are in [Install](install.md).

## `composer ci` fails because the docs site or handbook site is stale

Rebuild the stale side: `composer docs` rebuilds the docs site from `docs/`, and `composer site:handbook` rebuilds the handbook site. [Proof](reference/proof.md) covers what `composer ci` checks, including the stale-page failure.

## What is the difference between `refresh-policy` exit 3 and exit 4?

Exit 3 means something was written to `policy/policy.json` or `policy/sources.json`, including a released `loosen-fail` (the policy robot raises the alert from the report). Exit 4 means something was held or refused: whatever could be written was written, the held or refused items stayed out, and the policy robot runs its gate on whatever was written, as for exit 3, with an `auto-alert` issue per held or refused item. The refresher itself never commits. Exit 0 means nothing worth committing, and exit 1 is a hard failure. Version 0.1.2 changed a released loosening from exit 4 to exit 3; see the [Changelog](changelog.md).

## Why does the reader throw for a version between listed lines, or one that does not parse?

The reader refuses what it does not know, except the newest thing and the oldest. A version that sorts above every line the policy lists is `Active`, and a version that parses and sorts below every listed line is `Eol`, with the reason from `belowOldestLineReason()`. A version that falls between listed lines, or that does not parse, is still unknown, and still throws. Unlisted components are refused the same way. Current versions are never refused. The handbook's [Dependencies](../handbook/dependencies.html) chapter explains the states.

Creed Bratton would not care, but he is not reviewing the merge request.

## Related

- [Install](install.md)
- [Changelog](changelog.md)
- [Upgrading](upgrading.md)
- [Proof](reference/proof.md)
