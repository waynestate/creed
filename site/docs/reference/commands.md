---
title: "Commands and exit codes | CREED docs"
description: "Every command in bin/ and the exit codes it returns, from composer ci to the refresh tools and the policy robot."
url: https://creed.apps.wayne.edu/docs/reference/commands.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Commands and exit codes[#](https://creed.apps.wayne.edu/docs/reference/commands.html#commands-and-exit-codes)

This page lists every command CREED ships, what each one does, and the exit codes the refresh tools return.

## Composer scripts[#](https://creed.apps.wayne.edu/docs/reference/commands.html#composer-scripts)

| Command | What it does |
| --- | --- |
| `composer install` | Installs the project dependencies. |
| `composer ci` | All checks, what CI runs, in order: `composer validate --strict`, the tests, the policy validator, Pint, the handbook lint, the handbook site and agent-ready freshness checks, PHPStan, `composer audit` and the docs site freshness check. The same command runs on GitLab and GitHub. |
| `composer test` | Only the tests. |
| `composer validate-policy` | Validate `policy.json`: every line dated or marked current, then its schema and its `minimum_patch` rules. |
| `composer lint-handbook` | Lint the handbook. |
| `composer fix` | Fix code style. |
| `composer docs` | Rebuild `site/docs/` from `docs/` with the shared docs builder (SQUIRE's kit). |
| `composer docs:check` | Fail when `site/docs/` is stale against `docs/`, a nav path has no page, or a page fails the builder's checks. |
| `composer site:handbook` | Rebuild `site/handbook/` from `handbook/*.md`. |
| `composer site:handbook-check` | Fail when `site/handbook/` is stale against `handbook/*.md`. |
| `composer site:agent-ready` | Rewrite the Markdown twins, `llms.txt`, `llms-full.txt`, `robots.txt` and `sitemap.xml` in `site/` (SQUIRE's agent-ready kit). |
| `composer site:agent-ready-check` | Fail when those agent-ready files are stale. |
| `composer analyse` | PHPStan. |

Tip

On Windows, if tests fail oddly after a Docker install, delete `vendor/` and install again.

The whole band fits in three commands, and nobody needs a solo.

## The php bin/\*.php tools[#](https://creed.apps.wayne.edu/docs/reference/commands.html#the-php-bin-php-tools)

| Tool | What it does |
| --- | --- |
| `php bin/refresh-patches.php` | Refreshes the `latest_patch` and `latest_patch_checked` fields of the PHP, Laravel, and Node versions in `policy/policy.json` from the upstream feeds, and proposes new Vue lines from endoflife.date. |
| `php bin/refresh-policy.php` | Refreshes `policy/policy.json` from the canonical upstream feeds: support dates, latest patches, and new version lines, with provenance recorded in `policy/sources.json`. |
| `php bin/policy-bot.php run [--dry-run] [--offline <dir>]` | The CREED policy robot: it runs `bin/refresh-policy.php`, reads the run report, commits the policy data, runs `composer ci`, pushes straight to `main` with the job token when that passes (or opens `auto/policy-refresh` as a merge request labeled `auto-data` when it fails), and keeps its `auto-alert` issues in line. `--dry-run` prints the plan and changes nothing. See [The policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html). |
| `php bin/export.php` | Exports a namespace-prefixed copy of the CREED reader for a consumer package to carry under its own namespace, so two tools in one app never fight over a class name. |
| `php bin/validate-policy.php [--policy <path>]` | Fails, naming the component and version, when a line carries neither `security_until` nor `"current": true`, or both; then validates `policy/policy.json` against `policy/policy.schema.json`, fails when a line's optional `minimum_patch` is not in that line or is above its `latest_patch`, and validates `policy/sources.json` against `policy/sources.schema.json` when present. `--policy <path>` runs the policy checks on another file instead (the tests point it at fixtures). |
| `php bin/lint-handbook.php` | Lints the CREED handbook for RFC 2119 discipline. |
| `php bin/build-handbook-site.php` | Builds the handbook pages of the CREED site from `handbook/*.md`. |
| `php bin/agent-ready.php` | Makes a static front page readable by agents: writes the Markdown twins, `llms.txt`, `llms-full.txt`, `robots.txt` and `sitemap.xml` into the site folder. |
| `php bin/docs-site.php` | Turns every Markdown file under `docs/` into a committed, accessible, WayneUI-styled docs site under `site/docs/`, and fails CI when the committed pages are stale. |

## Exit codes[#](https://creed.apps.wayne.edu/docs/reference/commands.html#exit-codes)

### refresh-policy[#](https://creed.apps.wayne.edu/docs/reference/commands.html#refresh-policy)

| Code | Meaning |
| --- | --- |
| `0` | Nothing worth committing was written (and always `0` with `--dry-run`). |
| `1` | A hard failure: unreadable policy, unwritable file, bad option. |
| `3` | Something was written to `policy/policy.json` or `policy/sources.json`: tighten, neutral or loosen-warning changes, a loosen-fail released on proof (still reported as an alert by the policy robot), a first sighting in `policy/sources.json`, or a `--touch` heartbeat. |
| `4` | Something was held or refused. Whatever could be written was written; the held or refused items stay out. The policy robot runs the gate on whatever was written, the same as for exit 3, and opens an `auto-alert` issue per held or refused item. |

The refresher never commits; the policy robot does.

### policy-bot run[#](https://creed.apps.wayne.edu/docs/reference/commands.html#policy-bot-run)

| Code | Meaning |
| --- | --- |
| `0` | Every handled outcome: nothing to do, the gate passed and the refresh was pushed to `main` (or nothing staged), the alert issues synced, or a dry run. |
| `1` | The refresher hard-failed or could not start, a required CI variable is missing (named in the output), the pipeline is not on the default branch, a git step or the push failed, the gate failed (the refresh went to `auto/policy-refresh` as a merge request), or the alert issues could not be synced. After a failed gate or push the alert issues still sync first. |

### refresh-patches[#](https://creed.apps.wayne.edu/docs/reference/commands.html#refresh-patches)

| Code | Meaning |
| --- | --- |
| `0` | Nothing to change (or `--dry-run` with nothing to change). |
| `1` | A feed could not be fetched or parsed. |
| `3` | The policy was updated (or `--dry-run` with changes to show). In CI the scheduled job is check-only: when the policy changed, the tool exits 3 and the job fails on the diff. |

### export --check[#](https://creed.apps.wayne.edu/docs/reference/commands.html#export-check)

`export --check` compares the copy against a fresh export and exits `1` naming every file that differs, is missing, or is an extra `.php`/`.json` file. In short: it fails when the copy drifts from the source.

## With only Docker[#](https://creed.apps.wayne.edu/docs/reference/commands.html#with-only-docker)

The same lines work in PowerShell and sh.

### Install and run every check[#](https://creed.apps.wayne.edu/docs/reference/commands.html#install-and-run-every-check)

sh

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

PowerShell

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

### Run the test suite[#](https://creed.apps.wayne.edu/docs/reference/commands.html#run-the-test-suite)

No PHP on the machine? Fine. Docker runs the suite:

sh

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

PowerShell

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

### Validate the policy against its schema[#](https://creed.apps.wayne.edu/docs/reference/commands.html#validate-the-policy-against-its-schema)

sh

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 composer validate-policy
```

PowerShell

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 composer validate-policy
```

## Related[#](https://creed.apps.wayne.edu/docs/reference/commands.html#related)

- [Policy file format](https://creed.apps.wayne.edu/docs/reference/policy-format.html)
- [Configuration](https://creed.apps.wayne.edu/docs/reference/configuration.html)
- [Keeping the patches fresh](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html)
- [Refreshing the policy](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html)
- [The policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html)
- [Carrying CREED in another package](https://creed.apps.wayne.edu/docs/guides/carrying-creed.html)
