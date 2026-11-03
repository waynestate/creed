---
title: The policy robot
description: How bin/policy-bot.php run commits the data allowlist on auto/policy-refresh and opens a merge request labeled auto-data, and how to put it on the schedule.
---

# The policy robot

This page explains how the policy robot commits the refresh output as a merge request, and how to put it on the schedule.

## What the robot does

The policy robot (`bin/policy-bot.php run`) is the committing path: it commits the data allowlist on `auto/policy-refresh` and pushes it as a merge request labeled `auto-data`; a data-only run is auto-merged, and missing CI variables stop the run before any push.

> [!NOTE]
> The robot never tags or releases, and support dates stay hand-edited: a person reviews each support-date edit.

## The CI job

The `policy-bot` job in `.gitlab-ci.yml` runs `bin/policy-bot.php run` with `CREED_BOT_TOKEN`: on its dedicated schedule (the one carrying `CREED_JOB=policy-bot`) and by hand from the web. Without the token the job prints "CREED_BOT_TOKEN not set; skipping the policy robot" and stays green. The check-only refresh-patches job skips that schedule.

## Putting the robot on the schedule

To put the robot on the schedule, the owner creates three things on the CREED project:

1. A project access token (Settings > Access tokens): role Maintainer (Developer works only if Developers may merge to the default branch), scopes `api` and `write_repository`, an expiry of 90 days, then rotate.
2. A CI/CD variable `CREED_BOT_TOKEN` holding the token: masked and hidden, protected, not a file. The schedule runs on `main`, which is a protected branch, so the protected variable is available there.
3. A schedule on `main` (CI/CD > Schedules): weekly, Monday, with the variable `CREED_JOB` = `policy-bot`. The existing check-only schedule (no variable) can stay or be replaced by this one.

> [!WARNING]
> The branch `auto/policy-refresh` must not be protected, or the robot cannot push it.

The schedules are GitLab project settings, not files in this repo. The GitHub mirror runs the same check on a cron and on demand.

## Related

- [Keeping the patches fresh](patch-refresh.md)
- [Refreshing the policy](policy-refresh.md)
- [Contributing](../contributing.md)
