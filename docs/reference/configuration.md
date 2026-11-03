---
title: Configuration
description: The files a CREED consumer carries: policy/policy.json and policy-overrides.json with its reason and expiry.
---

# Configuration

This page lists the environment CREED reads and the options of `bin/refresh-policy.php`.

## Environment variables

| Variable | What it is |
|---|---|
| `CREED_BOT_TOKEN` | A CI/CD variable holding the project access token for the policy robot. Masked and hidden, protected, not a file. The schedule runs on `main`, which is a protected branch, so the protected variable is available there. Without the token the `policy-bot` job prints "CREED_BOT_TOKEN not set; skipping the policy robot" and stays green. |
| `CREED_JOB` | The schedule variable, set to `policy-bot` on the robot's dedicated schedule (weekly, Monday). The `policy-bot` job in `.gitlab-ci.yml` runs `bin/policy-bot.php run` on that schedule and by hand from the web; the check-only refresh-patches job skips it. |

`src/Bot/PolicyBot.php` also reads six GitLab predefined variables: `CI_PROJECT_ID`, `CI_SERVER_PROTOCOL`, `CI_SERVER_HOST`, `CI_SERVER_PORT`, `CI_PROJECT_PATH` and `CI_DEFAULT_BRANCH`. Each is a GitLab predefined variable the robot uses to find the project and default branch, nothing more. Missing CI variables stop the run before any push.

> [!WARNING]
> The token is masked, hidden and protected, and never printed. Every line the robot prints, including runner errors and command echoes, passes through one mask function that replaces the token value with `[masked]`, so a runner error that echoes the URL back never leaks it.

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
