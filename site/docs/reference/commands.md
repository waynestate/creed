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
| `composer ci` | All checks, what CI runs: `composer validate --strict`, the tests, the policy validator, Pint, the handbook lint, PHPStan and `composer audit`. The same command runs on GitLab and GitHub. |
| `composer test` | Only the tests. |
| `composer validate-policy` | Validate `policy.json` against its schema. |
| `composer lint-handbook` | Lint the handbook. |
| `composer fix` | Fix code style. |
| `composer docs` | Rebuild `site/docs/` from `docs/` with the shared docs builder (SQUIRE's kit). |
| `composer docs:check` | Fail when `site/docs/` is stale against `docs/`, a nav path has no page, or a page fails the builder's checks. |
| `composer site:handbook` | Rebuild `site/handbook/` from `handbook/*.md`. |
| `composer site:agent-ready` | Rewrite the Markdown twins, `llms.txt`, `llms-full.txt`, `robots.txt` and `sitemap.xml` in `site/` (SQUIRE's agent-ready kit). |

> \[!TIP\] On Windows, if tests fail oddly after a Docker install, delete `vendor/` and install again.

The whole band fits in three commands, and nobody needs a solo.

## The php bin/\*.php tools[#](https://creed.apps.wayne.edu/docs/reference/commands.html#the-php-bin-php-tools)

| Tool | What it does |
| --- | --- |
| `php bin/refresh-patches.php` | Refreshes the `latest_patch` and `latest_patch_checked` fields of the PHP, Laravel, and Node versions in `policy/policy.json` from the upstream feeds, and proposes new Vue lines from endoflife.date. |
| `php bin/refresh-policy.php` | Refreshes `policy/policy.json` from the canonical upstream feeds: support dates, latest patches, and new version lines, with provenance recorded in `policy/sources.json`. |
| `php bin/policy-bot.php run` | The CREED policy robot: it keeps `policy/policy.json` current with no human in the loop. It runs `bin/refresh-policy.php` as a subprocess, reads the run report, and decides the outcome. |
| `php bin/export.php` | Exports a namespace-prefixed copy of the CREED reader for a consumer package to carry under its own namespace, so two tools in one app never fight over a class name. |
| `php bin/validate-policy.php` | Validates `policy/policy.json` against `policy/policy.schema.json`, and validates `policy/sources.json` against `policy/sources.schema.json` when present. |
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
| `4` | Something was held or refused; nothing is committed for that run. The policy robot raises an issue instead. |

Written changes are always written.

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

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

### Run the test suite[#](https://creed.apps.wayne.edu/docs/reference/commands.html#run-the-test-suite)

No PHP on the machine? Fine. Docker runs the suite:

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

### Validate the policy against its schema[#](https://creed.apps.wayne.edu/docs/reference/commands.html#validate-the-policy-against-its-schema)

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate-policy
```

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate-policy
```

## Related[#](https://creed.apps.wayne.edu/docs/reference/commands.html#related)

- [Policy file format](https://creed.apps.wayne.edu/docs/reference/policy-format.html)
- [Configuration](https://creed.apps.wayne.edu/docs/reference/configuration.html)
- [Keeping the patches fresh](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html)
- [Refreshing the policy](https://creed.apps.wayne.edu/docs/guides/policy-refresh.html)
- [The policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html)
- [Carrying CREED in another package](https://creed.apps.wayne.edu/docs/guides/carrying-creed.html)
