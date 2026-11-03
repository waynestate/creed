---
title: "Contributing | CREED docs"
description: "How to build, test and release CREED, and where its data comes from, as printed in MAINTAINING.md."
url: https://creed.apps.wayne.edu/docs/contributing.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Contributing[#](https://creed.apps.wayne.edu/docs/contributing.html#contributing)

This page tells you how to build, test and release CREED, and how its policy data stays current.

## Where the code lives[#](https://creed.apps.wayne.edu/docs/contributing.html#where-the-code-lives)

Development happens on nougat, the canonical repository ([enterprise-apps/creed](https://nougat.snu.gs/enterprise-apps/creed)). This GitHub repository and git.wayne.edu are read-only mirrors: `main` and tags are copied automatically. Changes, issues and merge requests go to nougat. Nobody pushes to the mirrors.

## Build and test[#](https://creed.apps.wayne.edu/docs/contributing.html#build-and-test)

You need PHP 8.3 or newer and Composer, or only Docker. Everything has a native path on Windows (PowerShell), Linux and macOS.

| Task | Command |
| --- | --- |
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Only the tests | `composer test` |
| Validate `policy.json` against its schema | `composer validate-policy` |
| Lint the handbook | `composer lint-handbook` |
| Fix code style | `composer fix` |

With only Docker (the same line works in PowerShell and sh):

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

`composer ci` runs `composer validate --strict`, the tests, the policy validator, Pint, the handbook lint, PHPStan and `composer audit`. On Windows, if tests fail oddly after a Docker install, delete `vendor/` and install again.

## The docs site[#](https://creed.apps.wayne.edu/docs/contributing.html#the-docs-site)

The documentation site is built by `composer docs` and checked by `composer docs:check`. Its sources live in `docs/`, one Markdown file per page, and `composer ci` fails when the committed pages go stale.

## CI[#](https://creed.apps.wayne.edu/docs/contributing.html#ci)

- **GitLab:** Preflight components (`php-test` on PHP 8.3, 8.4 and 8.5, `composer-audit`, and `proctor` for the `deploy/` layer), plus a `composer-ci` job. A `refresh-patches` job runs on scheduled pipelines only.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, and runs `refresh-patches` weekly (Mondays) and on demand.
- Green means `composer ci` passed on all three PHP versions on both.

## Keeping the policy current[#](https://creed.apps.wayne.edu/docs/contributing.html#keeping-the-policy-current)

This is CREED's real maintenance job.

- **Patch levels are watched automatically.** `bin/refresh-patches.php` reads php.net (PHP), Packagist (Laravel), nodejs.org (Node) and endoflife.date (a PHP cross-check and Vue lines). It updates `latest_patch` and `latest_patch_checked`, and proposes new Vue lines. In CI the scheduled job is check-only: the refresh tool exits 3 when the policy changed and the job fails on the diff. The policy robot (`bin/policy-bot.php run`) commits the data allowlist on `auto/policy-refresh` and pushes it as a merge request labeled `auto-data`, auto-merged for a data-only run; missing CI variables stop the run before any push. It never tags or releases, and support dates stay hand-edited: a person reviews each support-date edit. The branch `auto/policy-refresh` must not be protected. The schedules live in the project's CI/CD settings on nougat, not in this repo.
- **Support dates are edited by hand.** `active_until` and `security_until` for existing lines change only when a person edits them, with the upstream source in the commit message. End days are inclusive.
- **A red scheduled run is a to-do, not an outage:** run `php bin/refresh-patches.php`, read the diff, `composer ci`, add a CHANGELOG line, commit.
- **After any policy change,** the copies downstream are stale until refreshed. In each consumer (PROCTOR, Rollcall, Booster), run `php <creed>/bin/export.php --namespace='Waynestate\<Project>\Creed' --to=src/Creed`, then `composer creed-copy:check` with `CREED_PATH` pointing at this checkout. ADVISOR regenerates with `content:generate`.
- The schema is `version: 1`. Optional new fields keep it at 1; a breaking schema change needs a new version and a release of every reader. The older-reader contract is pinned by `tests/OlderReaderContractTest.php`; change the fixture only with a `schema_version` bump.

## Releasing[#](https://creed.apps.wayne.edu/docs/contributing.html#releasing)

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, leaving an empty `## Unreleased`. (0.1.0 predates the heading convention, so it has none.)
2. `composer ci` green locally, then on both pipelines for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on nougat. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. Then bump `waynestate/creed` where the stack pins it, and refresh the generated copies.

## The front page and its deploy[#](https://creed.apps.wayne.edu/docs/contributing.html#the-front-page-and-its-deploy)

`site/` is the static front page. It is served on Citadel at `https://creed.apps.wayne.edu/` (pre-production first) by the raw-PHP layer in `deploy/`: HELMET's headers in front, PROCTOR and this policy in the release. `deploy/` is its own Composer project, separate from the library. Most of it is generated by Squire (`deploy/.squire.json` records what), so regenerate it rather than hand-editing it. Only `deploy/public/index.php`, `deploy/public/.htaccess` and `deploy/composer.json` are written by hand. Run the parity kit with `deploy\scripts\parity.ps1` (Windows) or `deploy/scripts/parity.sh` (Linux and macOS).

## Things that trip people up[#](https://creed.apps.wayne.edu/docs/contributing.html#things-that-trip-people-up)

- `composer.json` pins Pinstripe for dev; PROCTOR warns on any `dev-main` pin.
- A scheduled `refresh-patches` failure is expected whenever upstream ships a patch.
- No em or en dashes anywhere in the docs; the handbook lint and the house style both care. Creed Bratton would not care, but he is not reviewing the merge request.

## Related[#](https://creed.apps.wayne.edu/docs/contributing.html#related)

- [Overview](https://creed.apps.wayne.edu/docs/index.html)
- [Install](https://creed.apps.wayne.edu/docs/install.html)
- [Quick start](https://creed.apps.wayne.edu/docs/guides/quick-start.html)
- [Keeping the patches fresh](https://creed.apps.wayne.edu/docs/guides/patch-refresh.html)
- [Changelog](https://creed.apps.wayne.edu/docs/changelog.html)
