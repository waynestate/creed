---
title: "Configuration | CREED docs"
description: "The CI variables the policy robot reads (CREED_BOT_TOKEN, CREED_JOB and GitLab's predefined ones) and the options of bin/refresh-policy.php."
url: https://creed.apps.wayne.edu/docs/reference/configuration.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Configuration[#](https://creed.apps.wayne.edu/docs/reference/configuration.html#configuration)

This page lists the environment CREED reads and the options of `bin/refresh-policy.php`.

## Environment variables[#](https://creed.apps.wayne.edu/docs/reference/configuration.html#environment-variables)

| Variable | What it is |
| --- | --- |
| `CREED_BOT_TOKEN` | A CI/CD variable holding the project access token for the policy robot. Masked and hidden, protected, not a file. The schedule runs on `main`, which is a protected branch, so the protected variable is available there. Without the token the `policy-bot` job prints "CREED_BOT_TOKEN not set; skipping the policy robot" and stays green. |
| `CREED_JOB` | A pipeline schedule variable, set to `policy-bot` on the robot's schedule (on `main`, every Monday). The `policy-bot` job in `.gitlab-ci.yml` runs `bin/policy-bot.php run` on that schedule and by hand from the web; the check-only `refresh-patches` job skips it. |

`src/Bot/PolicyBot.php` also reads seven GitLab predefined variables, which every GitLab job has: `CI_API_V4_URL` and `CI_PROJECT_ID` for the alert issues, and `CI_SERVER_PROTOCOL`, `CI_SERVER_HOST`, `CI_SERVER_PORT`, `CI_PROJECT_PATH` and `CI_DEFAULT_BRANCH` for the push. A real run checks the first set before it refreshes and the second before it commits; when one is missing it names it and stops with exit 1, before any push. A `--dry-run` needs none of them.

Warning

The token is masked, hidden and protected, and never printed. Every line the robot prints, including runner errors and command echoes, passes through one mask function that replaces the token value with `[masked]`, so a runner error that echoes the URL back never leaks it.

## refresh-policy options[#](https://creed.apps.wayne.edu/docs/reference/configuration.html#refresh-policy-options)

```
php bin/refresh-policy.php [--dry-run] [--offline <dir>] [--policy <path>]
    [--sources <path>] [--report <file>] [--today YYYY-MM-DD] [--touch]
```

| Option | What it does |
| --- | --- |
| `--dry-run` | Print what would change without writing anything. |
| `--offline <dir>` | Read the feeds from `<dir>` instead of fetching them. Expects the feed files named after each source in `tests/fixtures/feeds/`. |
| `--policy <path>` | Path to the policy file (default: `policy/policy.json`). |
| `--sources <path>` | Path to the provenance ledger (default: `sources.json` next to the policy). |
| `--report <file>` | Also write a machine-readable report of the run. The report is JSON with `changes`, `held`, `refused`, `disagreements` and `source_errors`. |
| `--today YYYY-MM-DD` | Pretend today is this date (deterministic runs). |
| `--touch` | Set `refreshed_on` to today even when nothing else changed: the robot's weekly heartbeat. |

## Related[#](https://creed.apps.wayne.edu/docs/reference/configuration.html#related)

- [Commands and exit codes](https://creed.apps.wayne.edu/docs/reference/commands.html)
- [Policy file format](https://creed.apps.wayne.edu/docs/reference/policy-format.html)
- [The policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html)
- [Refreshing the policy](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html)
