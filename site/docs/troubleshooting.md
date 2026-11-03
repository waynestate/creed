---
title: "Troubleshooting | CREED docs"
description: "The versions the reader refuses and why: unknown components, versions between listed lines, and lines that do not parse."
url: https://creed.apps.wayne.edu/docs/troubleshooting.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Troubleshooting[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#troubleshooting)

Answers to the failures that look alarming but are expected, each with the page that explains the behavior.

## Why did the scheduled `refresh-patches` job fail?[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#why-did-the-scheduled-refresh-patches-job-fail)

A scheduled `refresh-patches` failure is expected whenever upstream ships a patch. The scheduled job is check-only: when the policy changed, the tool exits 3 and the job fails on the diff. Treat the red run as a to-do, not an outage: run `php bin/refresh-patches.php`, read the diff, run `composer ci`, add a CHANGELOG line, and commit. The [Changelog](https://creed.apps.wayne.edu/docs/changelog.html) is where that line goes.

## Why does the `policy-bot` job print "CREED_BOT_TOKEN not set; skipping the policy robot" and stay green?[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#why-does-the-policy-bot-job-print-creed-bot-token-not-set-skipping-the-policy-robot-and-stay-green)

That is the designed behavior when the masked `CREED_BOT_TOKEN` CI variable is not set: the job prints the line and stays green. Missing CI variables stop the run before any push, so nothing is committed. The job runs on its dedicated schedule (the one carrying `CREED_JOB=policy-bot`) and by hand from the web. It landed in 0.1.6; see the [Changelog](https://creed.apps.wayne.edu/docs/changelog.html).

## Why can the robot not push when `auto/policy-refresh` is protected?[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#why-can-the-robot-not-push-when-auto-policy-refresh-is-protected)

The branch `auto/policy-refresh` must not be protected, or the robot cannot push it. The robot commits the data allowlist on that branch and pushes it as a merge request labeled `auto-data`; a data-only run is auto-merged. Branch protection is a project setting, not a file in this repo. The [Changelog](https://creed.apps.wayne.edu/docs/changelog.html) records when the job and its schedule arrived.

## Tests fail oddly on Windows after a Docker install[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#tests-fail-oddly-on-windows-after-a-docker-install)

Delete `vendor/` and install again. The install and Docker paths are in [Install](https://creed.apps.wayne.edu/docs/install.html).

## `composer ci` fails because the docs site or handbook site is stale[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#composer-ci-fails-because-the-docs-site-or-handbook-site-is-stale)

Rebuild the stale side: `composer docs` rebuilds the docs site from `docs/`, and `composer site:handbook` rebuilds the handbook site. [Proof](https://creed.apps.wayne.edu/docs/reference/proof.html) covers what `composer ci` checks, including the stale-page failure.

## What is the difference between `refresh-policy` exit 3 and exit 4?[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#what-is-the-difference-between-refresh-policy-exit-3-and-exit-4)

Exit 3 means something was written to `policy/policy.json` or `policy/sources.json`, including a released `loosen-fail` (the policy robot raises the alert from the report). Exit 4 means something was held or refused; nothing is committed for that run. Exit 0 means nothing worth committing, and exit 1 is a hard failure. Version 0.1.2 changed a released loosening from exit 4 to exit 3; see the [Changelog](https://creed.apps.wayne.edu/docs/changelog.html).

## Why does the reader throw for a version between listed lines, or one that does not parse?[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#why-does-the-reader-throw-for-a-version-between-listed-lines-or-one-that-does-not-parse)

The reader refuses what it does not know, except the newest thing and the oldest. A version that sorts above every line the policy lists is `Active`, and a version that parses and sorts below every listed line is `Eol`, with the reason from `belowOldestLineReason()`. A version that falls between listed lines, or that does not parse, is still unknown, and still throws. Unlisted components are refused the same way. Current versions are never refused. The handbook's [Dependencies](https://creed.apps.wayne.edu/handbook/dependencies.html) chapter explains the states.

Creed Bratton would not care, but he is not reviewing the merge request.

## Related[#](https://creed.apps.wayne.edu/docs/troubleshooting.html#related)

- [Install](https://creed.apps.wayne.edu/docs/install.html)
- [Changelog](https://creed.apps.wayne.edu/docs/changelog.html)
- [Upgrading](https://creed.apps.wayne.edu/docs/upgrading.html)
- [Proof](https://creed.apps.wayne.edu/docs/reference/proof.html)
