# MAINTAINING: keeping CREED on key

How to build, test, release and keep CREED current. CREED is the policy file and handbook every other part of the
Wayne State app stack reads, so a mistake here travels far. Like any good frontman, it sets the key and the band
follows.

## What CREED is and what it depends on

- `waynestate/creed` on Packagist: `policy/policy.json` (supported PHP, Laravel, Node and Vue versions, their support
  dates and newest patches), a framework-free PHP reader, and the handbook in `handbook/`.
- No runtime dependencies beyond PHP. Dev: PHPUnit, Pint and PHPStan (presets from Pinstripe), opis/json-schema.
- **Who reads it:** PROCTOR, Rollcall and Booster carry a generated copy of the reader and policy (made by
  `bin/export.php`); ADVISOR bundles the policy and handbook in its `data/`; HELMET can read a `policy.json` at runtime;
  apps and the stack's front-page deploys install `waynestate/creed` from Packagist.

## Where it lives

Development happens on nougat, the canonical repository ([enterprise-apps/creed](https://nougat.snu.gs/enterprise-apps/creed)).
This GitHub repository and git.wayne.edu are read-only mirrors: `main` and tags are copied automatically. Changes,
issues and merge requests go to nougat. Nobody pushes to the mirrors.

## Build and test

You need PHP 8.3 or newer and Composer, or only Docker. Everything has a native path on Windows (PowerShell), Linux
and macOS.

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Only the tests | `composer test` |
| Validate `policy.json` against its schema | `composer validate-policy` |
| Lint the handbook | `composer lint-handbook` |
| Fix code style | `composer fix` |

With only Docker (the same line works in PowerShell and sh):

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

`composer ci` runs `composer validate --strict`, the tests, the policy validator, Pint, the handbook lint, the
handbook site and agent-ready freshness checks, PHPStan, `composer audit` and the docs site freshness check. On Windows, if tests fail oddly after a Docker install, delete `vendor/` and install again.

## CI

- **GitLab:** Preflight components (`php-test` on PHP 8.3, 8.4 and 8.5, `composer-audit`, and `proctor` for the
  `deploy/` layer), plus `composer-ci` and `wayneui-copies` jobs. On scheduled pipelines, `refresh-patches`
  (check-only) runs on a schedule without `CREED_JOB`, and `policy-bot` on the Monday schedule that carries
  `CREED_JOB=policy-bot` (and by hand from the web).
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, and runs
  `refresh-patches` weekly (Mondays) and on demand.
- Green means the tests passed on all three PHP versions on both, and `composer ci` passed (on all three on
  GitHub, once on GitLab).

## Keeping the policy current

This is CREED's real maintenance job.

- **Patch levels are watched automatically.** `bin/refresh-patches.php` reads php.net (PHP), Packagist (Laravel),
  nodejs.org (Node) and endoflife.date (a PHP cross-check and Vue lines). It updates `latest_patch` and
  `latest_patch_checked`, and proposes new Vue lines. In CI the scheduled job is check-only: the refresh tool
  exits 3 when the policy changed and the job fails on the diff. The policy robot (`bin/policy-bot.php run`)
  runs the fuller `bin/refresh-policy.php` every Monday, commits `policy/policy.json` and `policy/sources.json`
  on `auto/policy-refresh` and pushes a merge request labeled `auto-data`: it merges itself after a refresher
  exit 3 once the pipeline passes, and waits for a person after an exit 4 (held or refused items, each with an
  `auto-alert` issue). Missing CI variables stop the run before any push. The `policy-bot` job in
  `.gitlab-ci.yml` runs it on the schedule carrying `CREED_JOB=policy-bot` and by hand; without the masked
  `CREED_BOT_TOKEN` variable it prints "CREED_BOT_TOKEN not set; skipping the policy robot" and stays green.
  It never tags or releases. Review steps: `docs/guides/policy-bot.md`. The branch `auto/policy-refresh` must
  not be protected. The owner's setup (access token, `CREED_BOT_TOKEN` variable, schedule) is the numbered list
  in "Keeping the patches fresh" in the README; the schedules live in the project's CI/CD settings on nougat,
  not in this repo.
- **Support dates move only through review.** The robot writes a date that moves earlier or a later
  `active_until`, and holds a later `security_until` until two sources or 14 days agree; a person who edits a
  date by hand puts the upstream source in the commit message. End days are inclusive.
- **A red scheduled run is a to-do, not an outage:** run `php bin/refresh-patches.php`, read the diff, `composer ci`,
  add a CHANGELOG line, commit.
- **After any policy change,** the copies downstream are stale until refreshed. In each consumer (PROCTOR, Rollcall,
  Booster), run `php <creed>/bin/export.php --namespace='Waynestate\<Project>\Creed' --to=src/Creed`, then
  `composer creed-copy:check` with `CREED_PATH` pointing at this checkout. ADVISOR regenerates with
  `content:generate`. See "Carrying CREED in another package" in the README.
- The schema is `version: 1`. Optional new fields keep it at 1; a breaking schema change needs a new version and a
  release of every reader.

The older-reader contract is pinned by `tests/OlderReaderContractTest.php`; change the fixture only with a
`schema_version` bump.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, leaving an empty
   `## Unreleased`. (0.1.0 predates the heading convention, so it has none.)
2. `composer ci` green locally, then on both pipelines for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on nougat. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. Then bump `waynestate/creed` where the stack pins it, and refresh the generated copies.

## The front page and its deploy

`site/` is the static front page. It is served on Citadel at `https://creed.apps.wayne.edu/` (pre-production first, at
`https://creed.appstest.wayne.edu/`) by the raw-PHP layer in `deploy/`: HELMET's headers in front, PROCTOR and this
policy in the release. `deploy/` is its own Composer project, separate from the library. Most of it is generated by
Squire (`deploy/.squire.json` records what), so regenerate it rather than hand-editing it. Only `deploy/public/index.php`,
`deploy/public/.htaccess` and `deploy/composer.json` are written by hand. Run the parity kit with
`deploy\scripts\parity.ps1` (Windows) or `deploy/scripts/parity.sh` (Linux and macOS).

When a stamp in `BEST_PRACTICES.md` changes, or a practice is added, update the front page in the same commit: the
tally on the title page, the contents heading, that chapter's stamp bar and clause range, and the stamp on any clause
the page reprints. `FrontPageTallyTest` recounts the stamps from `BEST_PRACTICES.md` and fails `composer ci` until
`site/index.html` agrees; then run `composer site:agent-ready` so `site/index.md` and `llms-full.txt` follow.

## Things that trip people up

- `composer.json` pins Pinstripe for dev; PROCTOR warns on any `dev-main` pin.
- A scheduled `refresh-patches` failure is expected whenever upstream ships a patch.
- No em or en dashes anywhere in the docs; the handbook lint and the house style both care. Creed Bratton would not
  care, but he is not reviewing the merge request.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, deploy
access): ask Enterprise Applications.
