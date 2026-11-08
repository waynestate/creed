---
title: The policy robot
description: How the Monday policy robot refreshes policy/policy.json, runs composer ci and pushes the refresh straight to main with the job token, falls back to a merge request labeled auto-data when the gate is red, opens alert issues labeled auto-alert, how it is set up, and how to review a red week.
---

# The policy robot

This page explains what the policy robot does every Monday, what it pushes or opens on the CREED project, how it is set up, and how a person handles a red week.

## What the robot does

The policy robot is `bin/policy-bot.php run` (the logic is `src/Bot/PolicyBot.php`). Once a week it runs the full refresher, [`bin/refresh-policy.php`](policy-refresh.md), which re-reads the upstream sources for support dates, latest patches and new version lines. Then it turns the refresher's exit code and report into one of four outcomes:

| Refresher exit | What the robot does |
|---|---|
| `0` nothing worth committing | Nothing, unless `refreshed_on` is 7 or more days old: then it runs the refresher again with `--touch` (the weekly heartbeat) and treats that run like any other. |
| `3` changes written | Commits `policy/policy.json` and `policy/sources.json` on main's commit, runs [the gate](#the-gate), and pushes to `main` when it is green. |
| `4` something held or refused | Commits whatever was written (often nothing) and runs the gate the same way. The held and refused items stay out and get alert issues. |
| `1` hard failure | Stops with exit 1. The job goes red and nothing is pushed. |

After every handled run, red or green, it also brings its [alert issues](#the-alert-issues) in line with the report.

> [!NOTE]
> The robot only ever stages `policy/policy.json` and `policy/sources.json`, and only on `main`: a pipeline on any other branch stops before it commits. It never tags, releases or touches code, and nothing it writes reaches `main` without `composer ci` passing on it first.

What it may write is decided by the refresher's change classes: a date that moves earlier (`tighten`), a new patch or a new line (`neutral`) and a later `active_until` (`loosen-warning`) are written; a later `security_until` (`loosen-fail`) is held until two sources or 14 days of the same answer prove it; removing anything, or touching `tools` or `rules`, is refused. [Refreshing the policy](policy-refresh.md) has the full table.

## The gate

The scheduled job runs as the schedule owner, so it can run the whole gate itself:

1. The robot commits the refresh on the commit the job checked out (main's), titled `policy: refresh from upstream sources`. The committer is `CREED policy bot`.
2. It runs `composer ci` in the same job, on that commit.
3. **Green:** it pushes the commit straight to `main` with the CI job token (`CI_JOB_TOKEN`), no force. GitLab starts no new pipeline for a job-token push, which is fine: the gate already ran. Nobody merges anything by hand. If `main` moved during the run, the push is refused and the job fails; the next run starts from the new `main`.
4. **Red:** it pushes the commit to `auto/policy-refresh` as a [merge request](#the-fallback-merge-request) for a person, and the job fails, so the schedule owner gets the pipeline mail.

## The fallback merge request

Only a red gate opens (or updates) a merge request:

- **Title:** `policy: refresh from upstream sources`, also the commit message.
- **Branch:** `auto/policy-refresh` into the default branch, with "delete source branch" set.
- **Label:** `auto-data`.
- **Never auto-merged.** A red gate needs a person.
- **Body:** the date of the run, then one line per change in the refresher's own words, `php 8.3: latest_patch 8.3.35 -> 8.3.36 [neutral]`, under `Changes:`, `Held:` and `Refused:`, then any `Disagreements:` between sources and any `Sources that failed this run:`.

The robot force-pushes `auto/policy-refresh` each time the gate is red, so the branch always holds the latest failed refresh and an open merge request is updated rather than duplicated. Anything a person commits to that branch is overwritten on the next red run.

## The alert issues

People hear from the robot through GitLab issues, one per alert key, labeled `auto-alert` and titled `[auto-alert] <key>`. They sync on every handled run, red or green, so a red week still shows its held and refused items:

| Key | Opened when |
|---|---|
| `held:<component>:<line>:<field>` | the refresher held a change (a later `security_until` not yet proven) |
| `refused:<component>:<line>:<field>` | the refresher refused a change (a removal, or a `tools` or `rules` edit) |
| `disagreement:<component>:<line>:<field>` | two sources disagree and the policy sides with neither |
| `upcoming-eol:<component>:<line>` | a listed line's `security_until` is 60 days away or less |

A key that is still there on a later run gets a note on its open issue instead of a second issue. A key that is gone closes its issue with a "Cleared on" note. The logic is `src/Bot/AlertIssues.php`.

## Reviewing what it does

1. **A green week needs nothing.** The refresh is on `main` with `composer ci` passed on it. Read the commit if you are curious: the job log lists every change the refresher wrote.
2. **A red week: read the failed job first.** Whether the failure is in the policy (the schema validator or a test) or unrelated (for example a new security advisory in `composer audit`), the job log shows it.
3. **Then read the merge request body.** Each change line names the component, the line, the field, the old and new value and its class. Check a date against its source: php.net for PHP, the Node.js release schedule for Node, endoflife.date for the rest.
4. **Fix the cause on `main`, then start the robot by hand** (Build > Pipelines > Run pipeline on `main`). It refreshes again from the new `main`, and a green gate pushes the refresh itself; then close the stale merge request, which a green run leaves alone. Merging the merge request by hand also works once its pipeline is green. Never push a fix to `auto/policy-refresh`: the next red run overwrites it.
5. **Decide the held and refused items by hand.** They are in the `auto-alert` issues (and in a red week's merge request body). A held `security_until` that you can prove from the vendor's page goes in by a normal merge request that cites the source; the issue closes itself on the next run.
6. **After a refresh lands,** the copies downstream are stale until refreshed: see [Carrying CREED in another package](carrying-creed.md). A release of CREED is still a person's call ([Contributing](../contributing.md)).

## How it is scheduled

The `policy-bot` job in `.gitlab-ci.yml` runs the robot in two cases only, both on the default branch: a scheduled pipeline whose schedule carries the variable `CREED_JOB=policy-bot`, and a pipeline started by hand from the web. CREED's schedule runs it on `main` every Monday. The check-only [`refresh-patches`](patch-refresh.md) job skips that schedule, so a robot pipeline runs only the robot. `GIT_DEPTH: "0"` gives the job the full history it needs to push its commit back.

Without the token the job prints "CREED_BOT_TOKEN not set; skipping the policy robot" and stays green.

## Setting it up

The setup lives in GitLab project settings, not in this repo. To set it up on CREED again, or on a fork:

1. **A project access token** (Settings > Access tokens) for the alert issues only: role Maintainer, scope `api`, an expiry of 90 days, then rotate. The push uses the job token, so `write_repository` is no longer needed; a token that already has it keeps working as it is.
2. **A CI/CD variable `CREED_BOT_TOKEN`** holding the token: masked and hidden, protected, not a file. The schedule runs on `main`, a protected branch, so the protected variable reaches it.
3. **A pipeline schedule on `main`** (Build > Pipeline schedules): weekly on Monday, with the variable `CREED_JOB` = `policy-bot`. The job token pushes as the schedule owner, so the owner must be allowed to push to the protected `main` (Maintainer or above).
4. **Allow job-token pushes:** turn on "Allow Git push requests to the repository" (Settings > CI/CD > Job token permissions).
5. **Leave `auto/policy-refresh` unprotected,** or the red-week fallback cannot push it.

The robot also reads GitLab's predefined variables (listed in [Configuration](../reference/configuration.md)); a real run that is missing any of them stops with exit 1 before it commits or pushes, and names the missing variables (names only, never values).

> [!WARNING]
> Every line the robot prints, including git's own errors, `composer ci` output and the command echoes, passes through one mask that replaces the job token and the bot token with `[masked]`. The job token travels only in the push URL and the bot token only in the `PRIVATE-TOKEN` header. Still: keep the variable masked, and rotate the token if a job log ever shows it.

## Trying it without GitLab

A dry run needs no token and changes nothing. It prints the outcome it would choose, the files it would commit, the merge request title and body, and the alert issues it would open:

```
php bin/policy-bot.php run --dry-run
php bin/policy-bot.php run --dry-run --offline tests/fixtures/feeds
```

`--offline` reads saved feeds instead of fetching them; `tests/fixtures/feeds/` carries a full set. Creed Bratton ran a dry run once, at the quarry. Nobody asked what it printed.

## Related

- [Refreshing the policy](policy-refresh.md)
- [Keeping the patches fresh](patch-refresh.md)
- [Commands and exit codes](../reference/commands.md)
- [Configuration](../reference/configuration.md)
- [Troubleshooting](../troubleshooting.md)
