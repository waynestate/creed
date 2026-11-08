---
title: Configuration
description: The CI variables the policy robot reads (CREED_BOT_TOKEN, CREED_JOB and GitLab's predefined ones) and the options of bin/refresh-policy.php.
---

# Configuration

This page lists the environment CREED reads and the options of `bin/refresh-policy.php`.

## Environment variables

| Variable | What it is |
|---|---|
| `CREED_BOT_TOKEN` | A CI/CD variable holding the project access token for the policy robot's alert issues (scope `api`; the push uses the job token, so `write_repository` is no longer needed). Masked and hidden, protected, not a file. The schedule runs on `main`, which is a protected branch, so the protected variable is available there. Without the token the `policy-bot` job prints "CREED_BOT_TOKEN not set; skipping the policy robot" and stays green. |
| `CREED_JOB` | A pipeline schedule variable, set to `policy-bot` on the robot's schedule (on `main`, every Monday). The `policy-bot` job in `.gitlab-ci.yml` runs `bin/policy-bot.php run` on that schedule and by hand from the web; the check-only `refresh-patches` job skips it. |

`src/Bot/PolicyBot.php` also reads nine GitLab predefined variables, which every branch pipeline has: `CI_API_V4_URL` and `CI_PROJECT_ID` for the alert issues, and `CI_SERVER_PROTOCOL`, `CI_SERVER_HOST`, `CI_SERVER_PORT`, `CI_PROJECT_PATH`, `CI_DEFAULT_BRANCH`, `CI_COMMIT_BRANCH` and `CI_JOB_TOKEN` for the push. The robot pushes only when `CI_COMMIT_BRANCH` is the default branch, and it pushes with `CI_JOB_TOKEN`, which needs "Allow Git push requests to the repository" on (CI/CD > Job token permissions). A real run checks the first set before it refreshes and the second before it commits; when one is missing it names it and stops with exit 1, before any push. A `--dry-run` needs none of them.

> [!WARNING]
> The token is masked, hidden and protected, and never printed. Every line the robot prints, including runner errors and command echoes, passes through one mask function that replaces the job token and bot token values with `[masked]`, so a runner error that echoes the URL back never leaks either.

## refresh-policy options

```
php bin/refresh-policy.php [--dry-run] [--offline <dir>] [--policy <path>]
    [--sources <path>] [--report <file>] [--today YYYY-MM-DD] [--touch]
```

| Option | What it does |
|---|---|
| `--dry-run` | Print what would change without writing anything. |
| `--offline <dir>` | Read the feeds from `<dir>` instead of fetching them. Expects the feed files named after each source in `tests/fixtures/feeds/`. |
| `--policy <path>` | Path to the policy file (default: `policy/policy.json`). |
| `--sources <path>` | Path to the provenance ledger (default: `sources.json` next to the policy). |
| `--report <file>` | Also write a machine-readable report of the run. The report is JSON with `changes`, `held`, `refused`, `disagreements` and `source_errors`. |
| `--today YYYY-MM-DD` | Pretend today is this date (deterministic runs). |
| `--touch` | Set `refreshed_on` to today even when nothing else changed: the robot's weekly heartbeat. |

## Related

- [Commands and exit codes](commands.md)
- [Policy file format](policy-format.md)
- [The policy robot](../guides/policy-bot.md)
- [Refreshing the policy](../guides/policy-refresh.md)
