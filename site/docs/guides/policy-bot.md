---
title: "The policy robot | CREED docs"
description: "How the Monday policy robot refreshes policy/policy.json, opens a merge request labeled auto-data and alert issues labeled auto-alert, how it is set up, and how to review what it opens."
url: https://creed.apps.wayne.edu/docs/guides/policy-bot.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The policy robot[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#the-policy-robot)

This page explains what the policy robot does every Monday, what it opens on the CREED project, how it is set up, and how a person reviews its work.

## What the robot does[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#what-the-robot-does)

The policy robot is `bin/policy-bot.php run` (the logic is `src/Bot/PolicyBot.php`). Once a week it runs the full refresher, [`bin/refresh-policy.php`](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html), which re-reads the upstream sources for support dates, latest patches and new version lines. Then it turns the refresher's exit code and report into one of four outcomes:

| Refresher exit | What the robot does |
| --- | --- |
| `0` nothing worth committing | Nothing, unless `refreshed_on` is 7 or more days old: then it runs the refresher again with `--touch` (the weekly heartbeat) and treats that run like any other. |
| `3` changes written | Commits `policy/policy.json` and `policy/sources.json` on `auto/policy-refresh` and pushes a merge request set to merge on its own when its pipeline passes. |
| `4` something held or refused | Commits whatever was written (often nothing) the same way, but the merge request waits for a person. |
| `1` hard failure | Stops with exit 1. The job goes red and nothing is pushed. |

After a handled run it also brings its [alert issues](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#the-alert-issues) in line with the report.

Note

The robot only ever stages `policy/policy.json` and `policy/sources.json`. It never tags, releases or touches code, and nothing it writes reaches `main` except through a merge request and its pipeline.

What it may write is decided by the refresher's change classes: a date that moves earlier (`tighten`), a new patch or a new line (`neutral`) and a later `active_until` (`loosen-warning`) are written; a later `security_until` (`loosen-fail`) is held until two sources or 14 days of the same answer prove it; removing anything, or touching `tools` or `rules`, is refused. [Refreshing the policy](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html) has the full table.

## The merge request[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#the-merge-request)

A run that has something to commit opens (or updates) one merge request:

- **Title:** `policy: refresh from upstream sources`, also the commit message. The committer is `CREED policy bot`.
- **Branch:** `auto/policy-refresh` into the default branch, with "delete source branch" set.
- **Label:** `auto-data`.
- **Auto-merge:** only after an exit 3 (everything that changed was written). After an exit 4 a person merges or closes it.
- **Body:** the date of the run, then one line per change in the refresher's own words, `php 8.3: latest_patch 8.3.35 -> 8.3.36 [neutral]`, under `Changes:`, `Held:` and `Refused:`, then any `Disagreements:` between sources and any `Sources that failed this run:`.

The robot force-pushes `auto/policy-refresh` on every run, so the branch always holds the latest refresh and an open merge request is updated rather than duplicated. Anything a person commits to that branch is overwritten on the next Monday.

## The alert issues[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#the-alert-issues)

People hear from the robot through GitLab issues, one per alert key, labeled `auto-alert` and titled `[auto-alert] <key>`:

| Key | Opened when |
| --- | --- |
| `held:<component>:<line>:<field>` | the refresher held a change (a later `security_until` not yet proven) |
| `refused:<component>:<line>:<field>` | the refresher refused a change (a removal, or a `tools` or `rules` edit) |
| `disagreement:<component>:<line>:<field>` | two sources disagree and the policy sides with neither |
| `upcoming-eol:<component>:<line>` | a listed line's `security_until` is 60 days away or less |

A key that is still there on a later run gets a note on its open issue instead of a second issue. A key that is gone closes its issue with a "Cleared on" note. The logic is `src/Bot/AlertIssues.php`.

## Reviewing what it opens[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#reviewing-what-it-opens)

1. **Read the merge request body first.** Each change line names the component, the line, the field, the old and new value and its class. Check a date against its source: php.net for PHP, the Node.js release schedule for Node, endoflife.date for the rest.
2. **Let a green exit 3 merge itself.** Its changes are all `tighten`, `neutral` or `loosen-warning`, and `composer ci` has passed on them.
3. **Read the pipeline when it is red.** A red pipeline blocks the auto-merge. Whether the failure is in the policy (the schema validator or a test) or unrelated (for example a new security advisory in `composer audit`), fix it on `main`, then bring the branch up to date: the merge request's Rebase button, or start the robot by hand (Build > Pipelines > Run pipeline on `main`), which rebuilds the branch from the new `main`. Re-running the old pipeline alone tests the old branch again. Never push a fix to `auto/policy-refresh`: the next run overwrites it.
4. **Decide the held and refused items by hand.** They are in the merge request body and each has an `auto-alert` issue. A held `security_until` that you can prove from the vendor's page goes in by a normal merge request that cites the source; the issue closes itself on the next run.
5. **After a merge,** the copies downstream are stale until refreshed: see [Carrying CREED in another package](https://creed.apps.wayne.edu/docs/guides/carrying-creed.html). A release of CREED is still a person's call ([Contributing](https://creed.apps.wayne.edu/docs/contributing.html)).

## How it is scheduled[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#how-it-is-scheduled)

The `policy-bot` job in `.gitlab-ci.yml` runs the robot in two cases only: a scheduled pipeline whose schedule carries the variable `CREED_JOB=policy-bot`, and a pipeline started by hand from the web. CREED's schedule runs it on `main` every Monday. The check-only [`refresh-patches`](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html) job skips that schedule, so a robot pipeline runs only the robot. `GIT_DEPTH: "0"` gives the job the full history it needs to force-push its branch.

Without the token the job prints "CREED_BOT_TOKEN not set; skipping the policy robot" and stays green.

## Setting it up[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#setting-it-up)

The setup lives in GitLab project settings, not in this repo. To set it up on CREED again, or on a fork:

1. **A project access token** (Settings > Access tokens): role Maintainer (Developer works only if Developers may merge to the default branch), scopes `api` (merge requests and alert issues) and `write_repository` (the push), an expiry of 90 days, then rotate.
2. **A CI/CD variable `CREED_BOT_TOKEN`** holding the token: masked and hidden, protected, not a file. The schedule runs on `main`, a protected branch, so the protected variable reaches it.
3. **A pipeline schedule on `main`** (Build > Pipeline schedules): weekly on Monday, with the variable `CREED_JOB` = `policy-bot`.
4. **Leave `auto/policy-refresh` unprotected,** or the robot cannot push it.

The robot also reads GitLab's predefined variables (listed in [Configuration](https://creed.apps.wayne.edu/docs/reference/configuration.html)); a real run that is missing any of them stops with exit 1 before it commits or pushes, and names the missing variables (names only, never values).

Warning

Every line the robot prints, including git's own errors and the command echoes, passes through one mask that replaces the token with `[masked]`, and the token travels only in the push URL and the `PRIVATE-TOKEN` header. Still: keep the variable masked, and rotate the token if a job log ever shows it.

## Trying it without GitLab[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#trying-it-without-gitlab)

A dry run needs no token and changes nothing. It prints the outcome it would choose, the files it would commit, the merge request title and body, and the alert issues it would open:

```
php bin/policy-bot.php run --dry-run
php bin/policy-bot.php run --dry-run --offline tests/fixtures/feeds
```

`--offline` reads saved feeds instead of fetching them; `tests/fixtures/feeds/` carries a full set. Creed Bratton ran a dry run once, at the quarry. Nobody asked what it printed.

## Related[#](https://creed.apps.wayne.edu/docs/guides/policy-bot.html#related)

- [Refreshing the policy](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html)
- [Keeping the patches fresh](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html)
- [Commands and exit codes](https://creed.apps.wayne.edu/docs/reference/commands.html)
- [Configuration](https://creed.apps.wayne.edu/docs/reference/configuration.html)
- [Troubleshooting](https://creed.apps.wayne.edu/docs/troubleshooting.html)
