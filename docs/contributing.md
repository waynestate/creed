---
title: Contributing
description: How to build, test and release CREED, and where its data comes from, as printed in MAINTAINING.md.
---

# Contributing

This page tells you how to build, test and release CREED, and how its policy data stays current.

## Where the code lives

Development happens on git.wayne.edu, the canonical repository ([enterprise-apps/creed](https://git.wayne.edu/enterprise-apps/creed)). GitHub ([waynestate/creed](https://github.com/waynestate/creed)) is a read-only push mirror: `main` and tags are copied automatically, and Packagist picks releases up from it. Changes, issues and merge requests go to git.wayne.edu; tags are made there, by the maintainer, with an approver. Nobody pushes to the mirror.

## Build and test

You need PHP 8.3 or newer and Composer, or only Docker. Everything has a native path on Windows (PowerShell), Linux and macOS.

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Only the tests | `composer test` |
| Validate `policy.json`: every line dated or marked current, then its schema and its `minimum_patch` rules | `composer validate-policy` |
| Lint the handbook | `composer lint-handbook` |
| Fix code style | `composer fix` |

With only Docker (the same line works in PowerShell and sh):

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

`composer ci` runs `composer validate --strict`, the tests, the policy validator, Pint, the handbook lint, the handbook site and agent-ready freshness checks, PHPStan, `composer audit` and the docs site freshness check. On Windows, if tests fail oddly after a Docker install, delete `vendor/` and install again.

## The docs site

The documentation site is built by `composer docs` and checked by `composer docs:check`. Its sources live in `docs/`, one Markdown file per page, and `composer ci` fails when the committed pages go stale.

## CI

- **GitLab:** Preflight components (`php-test` on PHP 8.3, 8.4 and 8.5, `composer-audit`, `proctor` for the `deploy/` layer, which blocks on a failing check, `a11y`, which runs axe-core on every page in `site/`, and `mysql56-migrate` as the `mysql56-probe` job, which asks a real MySQL 5.6.51 about the databases chapter on main and tag pipelines, and again as the `mysql84-probe` job, which asks MySQL 8.4.13), plus a `composer-ci` job and a `wayneui-copies` job. On scheduled pipelines, `refresh-patches` (check-only) runs on a schedule without `CREED_JOB`, and `policy-bot` runs on the Monday schedule that carries `CREED_JOB=policy-bot` (and by hand from the web).
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, and runs `refresh-patches` weekly (Mondays) and on demand.
- Green means the tests passed on all three PHP versions on both, and `composer ci` passed (on all three on GitHub, once on GitLab).

## Keeping the policy current

This is CREED's real maintenance job.

- **Patch levels are watched automatically.** `bin/refresh-patches.php` reads php.net (PHP), Packagist (Laravel), nodejs.org (Node) and endoflife.date (a PHP cross-check and Vue lines). It updates `latest_patch` and `latest_patch_checked`, and proposes new Vue lines. In CI the scheduled job is check-only: the refresh tool exits 3 when the policy changed and the job fails on the diff.
- **The policy robot lands the change every Monday.** `bin/policy-bot.php run` runs the fuller `bin/refresh-policy.php` (dates, patches, new lines), commits `policy/policy.json` and `policy/sources.json` on main's commit and runs `composer ci` in its own job. Green: it pushes straight to `main` with the CI job token, and nobody merges by hand (GitLab starts no new pipeline for that push). Red: it opens or updates `auto/policy-refresh` as a merge request labeled `auto-data` for a person, and the job fails, so the schedule owner gets mail. Each held or refused item has an `auto-alert` issue, red or green. It never tags or releases. Review steps and setup: [The policy robot](guides/policy-bot.md). The branch `auto/policy-refresh` must not be protected. The schedules live in the project's CI/CD settings on git.wayne.edu, not in this repo.
- **Support dates move only through review.** The robot writes a date that moves earlier or a later `active_until`, and holds a later `security_until` until two sources or 14 days agree. A person who edits a date by hand puts the upstream source in the commit message. End days are inclusive.
- **A red scheduled run is a to-do, not an outage:** run `php bin/refresh-patches.php`, read the diff, `composer ci`, add a CHANGELOG line, commit.
- **After any policy change,** the copies downstream are stale until refreshed. In each consumer (PROCTOR, Rollcall, Booster), run `php <creed>/bin/export.php --namespace='Waynestate\<Project>\Creed' --to=src/Creed`, then `composer creed-copy:check` with `CREED_PATH` pointing at this checkout. ADVISOR regenerates with `content:generate`.
- **A support date that moves also moves on the front page.** The "Inspect a version" form runs the reader's rules in the browser on a copy of the dates in `site/assets/creed.js`. `FrontPageInspectorTest` fails `composer ci` until the copy matches `policy.json` and prints the lines to paste, so a robot refresh that moves a date opens a merge request for a person instead of pushing. Patch levels are not in the copy, so the weekly patch refresh never trips it.
- The schema is `version: 1`. Optional new fields keep it at 1; a breaking schema change needs a new version and a release of every reader. The older-reader contract is pinned by `tests/OlderReaderContractTest.php`; change the fixture only with a `schema_version` bump.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, leaving an empty `## Unreleased`. (0.1.0 predates the heading convention, so it has none.)
2. `composer ci` green locally, then on both pipelines for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on git.wayne.edu. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. Then bump `waynestate/creed` where the stack pins it, and refresh the generated copies.

## The front page and its deploy

`site/` is the static front page. It is served on Citadel at `https://creed.apps.wayne.edu/` (pre-production first) by the raw-PHP layer in `deploy/`: HELMET's headers in front, PROCTOR and this policy in the release. `deploy/` is its own Composer project, separate from the library. Most of it is generated by Squire (`deploy/.squire.json` records what), so regenerate it rather than hand-editing it. Only `deploy/public/index.php`, `deploy/public/.htaccess` and `deploy/composer.json` are written by hand. Run it locally with [Parity](https://git.wayne.edu/enterprise-apps/parity): `parity init --from-squire` once inside `deploy/`, then `parity up`.

When a stamp in `BEST_PRACTICES.md` changes, or a practice is added, update the front page in the same commit: the tally on the title page, the contents heading, that chapter's stamp bar and clause range, and the stamp on any clause the page reprints. `FrontPageTallyTest` recounts the stamps from `BEST_PRACTICES.md` and fails `composer ci` until `site/index.html` agrees; then run `composer site:agent-ready` so `site/index.md` and `llms-full.txt` follow.

## Open questions

`OPEN_QUESTIONS.md` lists what CREED does not know yet, and the front page prints the same list as its errata slip. `OpenQuestionsTest` keeps the two level: every open errata row on the front page is named in the file, and every row the file names is open on the page. Closing a question deletes its entry, moves the row to Settled with the date, and adds a CHANGELOG line, in one merge request.

## Things that trip people up

- `composer.json` pins Pinstripe for dev; PROCTOR warns on any `dev-main` pin.
- A scheduled `refresh-patches` failure is expected whenever upstream ships a patch.
- No em or en dashes anywhere in the docs; the handbook lint and the house style both care. Creed Bratton would not care, but he is not reviewing the merge request.

## Related

- [Overview](index.md)
- [Install](install.md)
- [Quick start](guides/quick-start.md)
- [Keeping the patches fresh](guides/patch-refresh.md)
- [Changelog](changelog.md)
