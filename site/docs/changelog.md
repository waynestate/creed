---
title: "Changelog | CREED docs"
description: "All notable changes to this project."
url: https://creed.apps.wayne.edu/docs/changelog.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Changelog[#](https://creed.apps.wayne.edu/docs/changelog.html#changelog)

## Unreleased[#](https://creed.apps.wayne.edu/docs/changelog.html#unreleased)

- Docs: the handbook writes Rollcall as a word, not in capitals.
- Fixed: the pipeline's PROCTOR jobs no longer pass `--policy`, which PROCTOR 0.7 refuses in CI; PROCTOR checks against the CREED policy it bundles.
- Changed: the GitLab pipeline pins the Preflight components to 0.2.0 (was 0.1.4).
- Changed: the front page's deploy layer (`deploy/`) runs Squire's kit 0.6.0 (was 0.5.0): `scripts/release.sh` asks PROCTOR's deploy gate for a go or no-go on each release (a report, not a stop, unless `SQUIRE_PROCTOR=enforce`), and `.env.squire.example` lists the new setting. The layer pins HELMET `^0.4` (locked 0.4.0).
- Fixed: the front page's authorities table says Sourdough starts all five app shapes, from raw PHP to Inertia and Vue; it no longer says raw PHP only.
- Changed: the front-page deploy layer pins PROCTOR ^0.7 (lock: PROCTOR 0.7.0, was 0.4.0).
- Changed: CI caches Composer downloads per lock file (`.composer-cache`, keyed on `composer.lock` and `deploy/composer.lock`), so a pipeline stops fetching every package from Packagist on every run. Images already come through the Dependency Proxy.

## 0.1.7 (2026-11-07)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-7-2026-11-07)

- Changed: the front page's deploy layer (`deploy/`) runs Squire's kit 0.5.0 (was 0.3.4): `oracle-check.php` arrives, `scripts/release.sh` and `scripts/parity.ps1` take the new templates, and the two kit files Renovate had edited (`docker-compose.squire.yml`, `.squire/base-fallback.Dockerfile`, which it had moved to PHP 8.5) are Squire's template again, so `squire init --check` passes. The shared Renovate preset leaves kit files alone now. `site/assets/stack-env.js` takes the stack's one copy, with bindingless catch blocks.
- Changed: The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.
- Changed: practices 16, 25 and 43 move to Checked, and 59 moves from Not yet to Partially, held by five new checks on PROCTOR main: `node-eol` (the Node line an app pins), `env-outside-config`, `app-key` (a committed `.env` or `APP_KEY`, and the cookie session driver), `served-folder` and `world-writable`. Practices 24 and 55 move from Documented to Partially for the same checks. The tally is now Enforced 7, Checked 14, Partially 9, Documented 22, Not yet 9; the front page's chapter bars and clauses 2.6 and 4.1, the backlog, and the handbook's versions, code, secrets and production chapters name the checks, and the secrets chapter gains an `APP_KEY` rule (rotate a leaked key, no cookie sessions).
- Fixed: the front page's citation links (the clause numbers beside each stack part in the authorities table) are at least 24 px wide and tall, meeting the WCAG 2.5.8 target size at 320 px in light and dark.
- Changed: Practices 14, 15 and 21 move to Checked: PROCTOR's laravel-php, laravel-eol and composer-audit abandoned checks enforce them.
- Changed: the handbook walk reaches the last five chapters (code, runtime-and-headers, secrets, production, deploy-gate): every MUST and SHOULD rule now names its point, a PROCTOR check ID, a measuring command, or the plain words "no check exists yet"; practices 29 and 32 record the completed walk and what remains, and the site's handbook pages and search index are regenerated.
- Changed: practice 39 is stamped Checked: PROCTOR's `composer-version` check fails a Composer below the higher of its CVE floors and CREED's policy minimum (`tools.composer.minimum`, 2.8.0), and the best-practices intro now names the Preflight 0.1.4 pin CI runs; the front page's tally and chapter 7 bar follow.
- Changed: nine stale stamps re-checked against the tree (2026-11-06): practices 14, 39, 44 and 46 move to Partially, 24, 42 and 49 to Documented, 51 to Enforced, and 50 stays Partially with its pipeline fact corrected; the front page's tally, chapter bars and section 4.1 clause follow, its Docker quickstart pins composer:2.10.3 like the README, and two cross-references plus four backlog lines that contradicted the new record are fixed.
- Added: a site accessibility gate (axe-core in Playwright, WCAG 2.2 AA, run in Docker): `scripts/a11y.sh` on Linux and macOS, `scripts/a11y.ps1` on Windows; it checks the front page, every docs page and every handbook page in light and dark at 360 and 1280 px.
- Added: the abandoned-package rule (`rules.abandoned_packages`: ignore, report or fail, `fail` in the bundled policy) to the policy, schema and reader, with the handbook decision next to the audit rule.
- Fixed: the refresh command's tests use a frozen policy snapshot, so the policy robot's refresh merge requests no longer fail them.
- Fixed: the front page's contents and key now tell the same story as the tally. Every chapter's stamp bar is recounted from BEST_PRACTICES.md, chapter 11 (WSU developer standards) has its row, the key explains all five stamps, and the new Partially stamp has its own ink (moss, between Checked's ochre and Documented's slate), a half-dashed frame and hatched bars. §4.3 is stamped Documented as BEST_PRACTICES.md has it, and the chapter 4 and 7 introductions say so. BEST_PRACTICES.md's own key lists Partially.
- Added: `FrontPageTallyTest` fails `composer ci` when the front page's tally, contents heading, chapter bars, clause ranges, key or reprinted clause stamps drift from BEST_PRACTICES.md.
- Fixed: the front page tally matches BEST_PRACTICES.md again: eleven chapters, 61 practices, and the stamps recounted (Enforced 6, Checked 7, Partially 4, Documented 21, Not yet 23); the README screenshot alt text states no counts.
- Fixed: the policy robot's nothing-staged line prints a real newline instead of a literal backslash-n, and the refresher's exit 4 comment says what the robot really does: it commits whatever else the run wrote (often nothing) and pushes the merge request without auto-merge.
- Changed: the docs' dark mode is the code book read after hours (warm graphite stock, cream type, a pale tint of the green ink for links, gold foil for the title rule, the current page and focus) instead of a green wallpaper; every kit dark token is set by the theme. The cover's gold ribbon marks the page you are reading and each callout's stamp is pressed as it scrolls in (motion only under prefers-reduced-motion: no-preference); stamp frames wear the front page's ink texture; wide tables shade the side that scrolls.
- Fixed: the docs and README describe the policy robot as it runs: the Monday schedule, the four outcomes, the merge request (title, label, auto-merge only after exit 3, force-pushed branch), the auto-alert issues, how to review a robot merge request, and the setup. Support dates are no longer described as hand-edited only, refresher exit 4 no longer says nothing is committed, and the composer ci lists name every step.
- Fixed: docs name `waynestate/creed` 0.1.6 as the Packagist version, list all seven GitLab variables the robot reads, and say where each CI job runs on which PHP versions.
- Security: the front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).
- Fixed: the front page no longer scrolls sideways on phones or between 861 and 941px. On phones the running head sets like the docs (name over menu, two lines of three links, not sticky); the menu now hides Errata as intended instead of Inspect; the title-page book leaves room for its tabs in a narrow column.
- Added: per-Laravel-line `requires` (PHP range, MySQL and MariaDB minimums) in the policy, schema and reader.
- Added: `images.docker` for PHP 8.3 to 8.5 in the policy, and a base-image rebuild rule in the versions chapter.
- Changed: the docs site wears CREED's header, main menu, palette and fonts (docs/\_header.html, docs/theme/creed.css and a theme key in docs/docs.json), light and dark, with callouts as stamped notes.
- Changed: README commands use the pinned Composer image.
- Added: decisions recorded: a 3 day Composer cooldown once Composer 2.11 is in the base images, even Node majors only, strict schema with tolerant readers, schema version 2 deferred.
- Added: a docs site (site/docs/) built by the shared docs builder from docs/, checked in composer ci.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Added: handbook chapter Runtime and headers (HELMET's headers, proxy trust, debug off in production).
- Added: handbook chapter Accessibility (WCAG 2.2 AA as the target, an automated check, the open Title II question).

## 0.1.6 (2026-11-03)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-6-2026-11-03)

- Added: a policy-bot CI job (weekly schedule with CREED_JOB=policy-bot, or run by hand) that runs bin/policy-bot.php run with CREED_BOT_TOKEN; without the token it skips green. The check-only refresh-patches job now skips that schedule.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.

## 0.1.5 (2026-11-03)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-5-2026-11-03)

- Changed: WSU developer standards rules updated with the owners' answers (source home, catalog, change control, crawling).
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- Added: a `wayneui-copies` CI job (strict WayneUI copies gate).
- Changed: the scheduled refresh job only checks until a bot token is set; README and CI comments say so.
- Removed: the unused `composer/semver` dependency.
- Added: handbook rules name the PROCTOR check that enforces them.
- Fixed: BEST_PRACTICES statements match the tree.
- Added: handbook note on MySQL lines and auth plugin.
- Added: a test pins that an older reader accepts a newer policy file.
- Fixed: BEST_PRACTICES gap list matches the schema and tests.
- Added: handbook chapters Secrets and Production, from the WSU developer standards (2024).
- Added: WSU developer standards (2024) rules in the Code, Auth, Databases, Dependencies and CI chapters.
- Added: BEST_PRACTICES section 11 (practices 55 to 61) from the WSU developer standards (2024).

## 0.1.4 (2026-10-30)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-4-2026-10-30)

- Fixed: the bot's GitLab HTTP client failed on every API call (`stream_context_create` options were not under the `http` wrapper, a ValueError on current PHP). It now works, and reads response headers from the stream's metadata, so PHP 8.5 shows no deprecation. Found by Booster's hop-data robot, which uses this client.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## 0.1.3 (2026-10-29)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-3-2026-10-29)

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- Changed: project names written as words except acronyms.
- Changed: README images show the current site and policy.
- Changed: README names which tools carry the reader copy; the auth chapter lists the `oidc` driver.
- Changed: the agent-ready kit is Squire 0.3.0's, so generated labels read "Squire's agent-ready kit".
- `bin/policy-bot.php run`: a real (non dry-run) run now commits the data allowlist on `auto/policy-refresh` and force-pushes it with GitLab merge request push options (create, target, remove source branch, label `auto-data`, title; `merge_request.auto_merge` on exit 3 only). The push URL comes from the CI_SERVER\_\* variables and CREED_BOT_TOKEN; missing variables stop the run with exit 1 naming them, and every printed line masks the token as `[masked]`. After an exit 0, a refresh whose `refreshed_on` is 7 or more days old runs the refresher again with `--touch` and treats that result like a normal run.
- `bin/policy-bot.php run`: after a handled real run the robot now keeps its GitLab alert issues in line with the report, one open issue per alert key (`held:<component>:<line>:<field>`, `refused:<component>:<line>:<field>`, `disagreement:<component>:<line>:<field>` while policy sides with neither source, and `upcoming-eol:<component>:<line>` within 60 days of `security_until`), each labelled `auto-alert` and titled `[auto-alert] <key>`. A key that is still present gets a note on its existing issue; a key that is gone is closed with a "Cleared on ." note. The client is built from CI_API_V4_URL, CI_PROJECT_ID and CREED_BOT_TOKEN; an incomplete environment stops the run with exit 1 naming the missing variables. A dry run prints the issues it would open and never calls the API.
- New `src/Bot/GitLabClient.php`: a small GitLab API client (list open issues, create an issue, add a note, close an issue) over an injectable `HttpTransport`, shipped with a dependency-free PHP streams transport (`GitLabClient::streamTransport`). The token rides only in the `PRIVATE-TOKEN` header, never in a URL, and every error it throws is masked, so a failed call can never print the token.
- Fixed: stale capability claims in the site and docs.

## 0.1.2 (2026-10-29)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-2-2026-10-29)

- `bin/refresh-policy.php`: a released version that loosens a constraint now exits 3 (changes written) with an alert in the report, instead of exit 4.
- Fixed: a version of a known component that is older than the oldest listed line (PHP 5.x to 8.0, Laravel 5 to 8, Inertia 0.x) is now `Eol`, with the reason `older than the oldest listed line (8.1)` from the new `belowOldestLineReason($component, $version)`, instead of being refused as an unknown version. Unlisted components and version strings that do not parse are still refused, and a version above every listed line is still `Active`.

## 0.1.1 (2026-10-29)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-1-2026-10-29)

- `bin/refresh-policy.php` keeps every support date, patch and new line in the policy tied to a canonical source (php.net, the Node.js release schedule, endoflife.date, Packagist, npm). Changes are classed tighten, neutral, loosen-warning or loosen-fail; a later security date is held until two sources agree or 14 days pass; nothing is ever removed. Provenance lives in the new `policy/sources.json` (schema and validation included), and the policy gains an optional `refreshed_on` date. `refresh-patches.php` keeps working.
- PHPStan now includes Pinstripe's raw preset (`vendor/waynestate/pinstripe/phpstan/raw.neon`) instead of carrying a hand copy; `phpstan.neon` keeps only this repo's own paths and phpVersion. `waynestate/pinstripe` joins require-dev (dev-main until 0.1.0 is tagged) and `phpstan/extension-installer` is allowed so the preset's deprecation rules load. The suit is off the rack now.
- `handbook/dependencies.md` gains a "Private packages" section: internal-only packages install from one GitLab registry per ecosystem, credentials stay out of repository URLs and lock files, builds use read-only deploy tokens, the private Composer entry is canonical, released versions never move, and public code goes to Packagist or npm. PROCTOR checks and the PREFLIGHT publish job enforce the rules. One standard, no cover versions.
- Policy lines may be marked `"current": true`: Active on every date, with no `security_until`. The schema rejects a current line that also carries a `security_until`, and a line that carries neither.
- `stateOf()` resolves a version to its policy line before judging it. A version that sorts above every listed line is `Active` (current versions are never refused), while a version between listed lines still throws.
- New reader method `isAheadOfPolicy($component, $version)`.
- `line()` reports a fifth field, `current` (true or null).
- The bundled policy lists Vue 3 and Inertia 2 as current lines.
- `bin/refresh-patches.php` reads the endoflife.date Vue feed and proposes new Vue lines (a current line, or EOL dates) alongside the patch refresh.
- The README's reader example is now a test: `tests/ReadmeTest.php` runs every
  ```php
  values printed beside it. A snippet that cannot run as-is is skipped with a
  `<!-- readme-test: skip -->` comment.
  ```
- The handbook is now linted for RFC 2119 capitalization. `src/HandbookLinter.php` and `bin/lint-handbook.php` flag lowercase `must`, `must not`, `should`, `should not` and `may` in any chapter that declares RFC 2119, ignoring code spans and blocks; a same-line `<!-- lint: allow -->` excuses a deliberate plain-English use. `composer ci` runs the lint as `lint-handbook`.
- Every handbook chapter now declares RFC 2119 and uses capital keywords; `handbook/databases.md` gains a "Grace period" section spelling out the 90-day `eol_grace_days` window (`Security` on `security_until`, `Eol` for the window, `EolGraceExpired` after, no override).

## 0.1.0 (2026-10-27)[#](https://creed.apps.wayne.edu/docs/changelog.html#0-1-0-2026-10-27)

- First tagged release.
