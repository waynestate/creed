---
title: Commands and exit codes
description: Every command in bin/ and the exit codes it returns, from composer ci to the refresh tools and the policy robot.
---

# Commands and exit codes

This page lists every command CREED ships, what each one does, and the exit codes the refresh tools return.

## Composer scripts

| Command | What it does |
|---|---|
| `composer install` | Installs the project dependencies. |
| `composer ci` | All checks, what CI runs, in order: `composer validate --strict`, the tests, the policy validator, Pint, the handbook lint, the handbook site and agent-ready freshness checks, PHPStan, `composer audit` and the docs site freshness check. CI on git.wayne.edu runs the same command. |
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

> [!TIP]
> On Windows, if tests fail oddly after a Docker install, delete `vendor/` and install again.

The whole band fits in three commands, and nobody needs a solo.

## The php bin/*.php tools

| Tool | What it does |
|---|---|
| `php bin/refresh-patches.php` | Refreshes the `latest_patch` and `latest_patch_checked` fields of the PHP, Laravel, and Node versions in `policy/policy.json` from the upstream feeds, and proposes new Vue lines from endoflife.date. |
| `php bin/refresh-policy.php` | Refreshes `policy/policy.json` from the canonical upstream feeds: support dates, latest patches, and new version lines, with provenance recorded in `policy/sources.json`. |
| `php bin/policy-bot.php run [--dry-run] [--offline <dir>]` | The CREED policy robot: it runs `bin/refresh-policy.php`, reads the run report, commits the policy data, runs `composer ci`, pushes straight to `main` with the job token when that passes (or opens `auto/policy-refresh` as a merge request labeled `auto-data` when it fails), and keeps its `auto-alert` issues in line. `--dry-run` prints the plan and changes nothing. See [The policy robot](../guides/policy-bot.md). |
| `php bin/export.php` | Exports a namespace-prefixed copy of the CREED reader for a consumer package to carry under its own namespace, so two tools in one app never fight over a class name. |
| `php bin/validate-policy.php [--policy <path>]` | Fails, naming the component and version, when a line carries neither `security_until` nor `"current": true`, or both; then validates `policy/policy.json` against `policy/policy.schema.json`, fails when a line's optional `minimum_patch` is not in that line or is above its `latest_patch`, and validates `policy/sources.json` against `policy/sources.schema.json` when present. `--policy <path>` runs the policy checks on another file instead (the tests point it at fixtures). |
| `php bin/lint-handbook.php` | Lints the CREED handbook for RFC 2119 discipline. |
| `php bin/build-handbook-site.php` | Builds the handbook pages of the CREED site from `handbook/*.md`. |
| `php bin/agent-ready.php` | Makes a static front page readable by agents: writes the Markdown twins, `llms.txt`, `llms-full.txt`, `robots.txt` and `sitemap.xml` into the site folder. |
| `php bin/docs-site.php` | Turns every Markdown file under `docs/` into a committed, accessible, WayneUI-styled docs site under `site/docs/`, and fails CI when the committed pages are stale. |
| `php bin/mysql56-probe.php [--wait=SECONDS]` | Asks a live MySQL 5.6 whether the databases chapter tells the truth about it: runs every probe in `tools/Mysql56/Probes.php` against the server named by `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`, in the session Laravel 13 opens, and prints `[OK]` or `[FAIL]` per probe. `--wait` retries the connection while the server starts. Needs `pdo_mysql`, not Composer. On an 8.4 server it judges each probe against its 8.4 answer. `scripts/mysql56-probe.sh` and `scripts/mysql56-probe.ps1` start `mysql:5.6.51` (or `mysql:8.4.13`) in Docker and run it; CI runs it in the `mysql56-probe` and `mysql84-probe` jobs. |

## Exit codes

### refresh-policy

| Code | Meaning |
|---|---|
| `0` | Nothing worth committing was written (and always `0` with `--dry-run`). |
| `1` | A hard failure: unreadable policy, unwritable file, bad option. |
| `3` | Something was written to `policy/policy.json` or `policy/sources.json`: tighten, neutral or loosen-warning changes, a loosen-fail released on proof (still reported as an alert by the policy robot), a first sighting in `policy/sources.json`, or a `--touch` heartbeat. |
| `4` | Something was held or refused. Whatever could be written was written; the held or refused items stay out. The policy robot runs the gate on whatever was written, the same as for exit 3, and opens an `auto-alert` issue per held or refused item. |

The refresher never commits; the policy robot does.

### policy-bot run

| Code | Meaning |
|---|---|
| `0` | Every handled outcome: nothing to do, the gate passed and the refresh was pushed to `main` (or nothing staged), the alert issues synced, or a dry run. |
| `1` | The refresher hard-failed or could not start, a required CI variable is missing (named in the output), the pipeline is not on the default branch, a git step or the push failed, the gate failed (the refresh went to `auto/policy-refresh` as a merge request), or the alert issues could not be synced. After a failed gate or push the alert issues still sync first. |

### refresh-patches

| Code | Meaning |
|---|---|
| `0` | Nothing to change (or `--dry-run` with nothing to change). |
| `1` | A feed could not be fetched or parsed. |
| `3` | The policy was updated (or `--dry-run` with changes to show). In CI the scheduled job is check-only: when the policy changed, the tool exits 3 and the job fails on the diff. |

### mysql56-probe

| Code | Meaning |
|---|---|
| `0` | Every probe answered as `handbook/databases.md` says (on 8.4, as its 8.4 answer says). |
| `1` | At least one probe disagreed; its `[FAIL]` line says what the server did instead. Fix the chapter or the probe, in one commit. |
| `2` | It could not connect before `--wait` ran out, `pdo_mysql` is missing, or an argument is unknown. |

### export --check

`export --check` compares the copy against a fresh export and exits `1` naming every file that differs, is missing, or is an extra `.php`/`.json` file. In short: it fails when the copy drifts from the source.

## With only Docker

The same lines work in PowerShell and sh.

### Install and run every check

```sh tab="sh"
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

```powershell tab="PowerShell"
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

### Run the test suite

No PHP on the machine? Fine. Docker runs the suite:

```sh tab="sh"
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

```powershell tab="PowerShell"
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

### Validate the policy against its schema

```sh tab="sh"
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 composer validate-policy
```

```powershell tab="PowerShell"
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 composer validate-policy
```

### Probe MySQL 5.6

Starts `mysql:5.6.51` (amd64 only; Apple Silicon runs it under emulation), runs the probes in the databases chapter, and removes the server again:

```sh tab="sh"
sh scripts/mysql56-probe.sh
```

```powershell tab="PowerShell"
.\scripts\mysql56-probe.ps1
```

### Probe MySQL 8.4

The same probes on `mysql:8.4.13` (multi-arch), each judged against the answer 8.4 gives: the proof behind the chapter's "Moving to 8.4" section.

```sh tab="sh"
sh scripts/mysql56-probe.sh 8.4
```

```powershell tab="PowerShell"
.\scripts\mysql56-probe.ps1 -Server 8.4
```

## Related

- [Policy file format](policy-format.md)
- [Configuration](configuration.md)
- [Keeping the patches fresh](../guides/patch-refresh.md)
- [Refreshing the policy](../guides/policy-refresh.md)
- [The policy robot](../guides/policy-bot.md)
- [Carrying CREED in another package](../guides/carrying-creed.md)
