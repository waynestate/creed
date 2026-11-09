# Changelog

## Unreleased

- Docs: the every-OS rule (practice 34, backlog item 16, handbook `every-os.md`, front page §6.5) names PROCTOR main's `gitattributes-eol`, which ships in PROCTOR's next release and warns when `.gitattributes` leaves shell scripts, `.conf` files or Dockerfiles off LF and on a CRLF Dockerfile or `.conf` under `deploy/` or `.squire/`; practice 34 stays Partially (nothing checks the `.ps1` and `.sh` pair). The secrets chapter's error-page rule is now "Held by: HELMET" (the opt-in 500 handler, `helmet_client_error()` and the Laravel 4xx pages behind `HELMET_ERROR_PAGES`, on HELMET main) instead of "No check exists yet", and practice 26, the secrets chapter and front page §4.3 say `app-debug` shipped in PROCTOR 0.10.0. No CREED row covered the Entra client secret expiry Drawbridge now reminds about, so none changed. The tally is unchanged.
- Fixed: `bin/validate-policy.php` names the line when a policy line carries neither `security_until` nor `"current": true`, or carries both, and says how to fix it. The schema already refused such a line, but its message ("The properties must match schema") named nothing. An undated line would make `Policy::supported()` and `oldestSupported()` throw for the whole component in PROCTOR and ADVISOR, so CI now stops it with a message that names the component and version; the reader is unchanged.
- Docs: BEST_PRACTICES catches up with PROCTOR 0.10.0 (mysql-minimum, app-debug, lock-committed, preflight-pinned), Pinstripe 0.3.0's joined-SQL ban, Sourdough's npm audit step, the GitLab tier (19.5.1 CE) and Composer 2.11's cooldown. Practice 56 (validate input) is now Checked, so the front page's tally reads 18 Checked and 18 Documented and chapter 11's bar follows.
- Changed: four handbook rules name the PROCTOR 0.10.0 check that reports them instead of "No check exists yet": `csp-unsafe-inline` and `display-errors` (runtime and headers), `directory-listing` and `robots-txt` (production). The robots.txt rule says an app that wants crawling writes `Allow: /` for `User-agent: *`.
- Changed: the deploy kit is Squire 0.10.0 (was 0.7.1): release.sh keeps Composer and npm download caches in the app folder and the gate takes SQUIRE_PHP_RUNTIME; the compose file, health.php and .env.squire.example follow the template, and the portable image files are added.

## 0.1.8 (2026-11-09)

- Fixed: scripts/a11y.ps1 works on a Windows checkout with CRLF line endings; it strips the carriage returns from the shell command it hands to Docker, which sh rejected with a syntax error.
- Docs: the front page and docs pages follow the reader's dark mode: the front page and the handbook pages now read after hours like the docs already did, with warm graphite stock, cream type, the green ink thinned to a pale tint and gold foil for focus, while the green cloth (spine, cover, tabs, the ink button, colophon) stays put. Every text pair meets WCAG AA, the browser bar color and form controls follow the scheme, printing stays ink on white, and with motion allowed a lamp glint runs across the cover's foil once it is struck. The docs theme now says it shares those values with the front page.
- Docs: the front page's Table 2-1 carries the policy's Laravel patches (12.71.0 and 13.40.0, checked 2026-11-08; it still showed 12.70.2 and 13.39.0) and says patches were checked 2026-10-28 to 2026-11-08, and the install appendix names the Packagist release in use (0.1.7, was 0.1.3).
- Added: an optional `minimum_patch` per PHP line in policy.json, read by `Policy::minimumPatch()` and checked by `bin/validate-policy.php`; no values are set yet.
- Docs: BEST_PRACTICES practice 26 (debug off in production) is now Checked, held by PROCTOR main's `app-debug` and HELMET's boot warning, and practice 28 (staged rollout) is now Partially, since `proctor gate` 0.9.0 applies the policy's stage dates and ratchets against the baseline while no rule is staged yet. The front page follows: the tally, the chapter 4 and 5 bars, both clauses, and the runner note, which still said git.wayne.edu had none.
- Fixed: BEST_PRACTICES catches up with PROCTOR and its own policy. Practice 28 and backlog item 15 (now Done) say the policy's `gate` block carries stage dates and `proctor gate` (0.9.0) applies them; practices 26, 34 and 46 and backlog items 12, 16 and 28 name `app-debug`, `case-clash` and `mysql-minimum`, which they called unbuilt. No stamp moved in this change.
- Added: the handbook lint fails on a rule that names nothing holding it. Every rule that uses MUST, SHOULD or MAY carries "PROCTOR check: `<id>`", "Held by: ..." or "No check exists yet" (`HandbookLinter::untaggedRules()`, practice 29), and the 25 rules that had none are tagged. Fixed with them: the dependencies chapter no longer names `package-sources` and `internal-vendor-shadowing`, two PROCTOR checks that never shipped; the `APP_DEBUG` rules name PROCTOR main's `app-debug`; the every-OS chapter gains the `case-clash` rule; the databases chapter names `mysql-minimum`; and a test fails if an odd Node major is written into `policy.json`.
- Changed: CI pins the Preflight components to 0.4.0 (the proctor job clones the full history, so the override-approver check can read git blame).
- Changed: the code handbook names what Pinstripe enforces (presets from 0.1.4, the env rule, and the banned calls in Pinstripe 0.2.0 with their `pinstripe.banned.*` identifiers) instead of calling every rule Planned.
- Docs: BEST_PRACTICES.md practices 41 (front-end install options, now Partially: Squire's release install and the npm cooldown are built) and 45 (CI host, now Documented: the instances run GitLab 19.5.1 CE) carry their true status.
- Added: `rules.override_self_approval` (`warn`; `warn` or `fail`) in the policy and its schema, `Policy::overrideSelfApproval()` in the reader (`warn` when the key is absent), and the overrides chapter says an override is approved by someone who did not write it, with the merge request as the control and PROCTOR's `override-approver` check as the tripwire.
- Changed: the deploy layer runs Squire's kit 0.7.1 (was 0.6.0): `.squire/php.ini` no longer sets the deprecated `session.sid_*` lines, web requests get a `disable_functions` list and Apache hardening (`.squire/php-web.ini`, `.squire/apache-hardening.conf`), and opcache gets production settings with `SQUIRE_OPCACHE_VALIDATE` as the one switch.
- Docs: BEST_PRACTICES and the handbook name the Preflight release in use (0.3.1, was 0.2.0 or 0.3.0 in six places) and HELMET 1.0 for the CORP default.
- Changed: the front page deploy layer requires HELMET ^1.0 (lock at 1.0.1, was 0.4.0), like the rest of the stack; its shared front controller already runs on HELMET 1.0 in Squire, Sourdough and Pinstripe.
- Local a11y scripts now run Preflight's checker (vendored in tools/a11y/check.mjs at the same tag as CI), checking every page in light and dark at 320 and 1280 px.
- Added: CI runs Preflight's a11y component (0.3.1) on site/, every page in light and dark at 320 and 1280 px with the WCAG 1.4.10 reflow check, blocking.
- Changed: CI pins the Preflight components to 0.3.1 (fixed cache keys, and caches saved even when a job fails; D-072).
- Changed: CI saves its Composer and npm caches even when a job fails, so a retry after a dropped download fetches only what is missing (D-072).
- Changed: CI caches Composer and npm downloads under a fixed key, so a lock change downloads only what changed (D-072).
- Added: a `gate` block in the policy (`default_stage`, per-check `stages` with dates; ships empty) and `Policy::gateStage()`, so PROCTOR's gate can stage a rule as report, warn or block (ADR-0006 item 4, D-071).
- Added: `Policy::overrideMaxDays()` reads `rules.override_max_days` (90 when absent; anything but a positive integer throws), so PROCTOR's exported reader can enforce the override cap.
- Added: `rules.override_max_days` (90) in the policy and its schema, and the overrides chapter says an override may run at most that long.
- Fixed: the docs menu closes with Escape and hands focus back to its button (docs kit synced from Squire).
- Fixed: the docs kit's copy button no longer covers the first line of code when it says Copied or Failed: over the code it keeps its resting width and shows a check or a cross when the word does not fit (docs kit synced from Squire).
- Changed: the deploy layer requires PROCTOR ^0.8 (locked 0.8.0).
- Changed: the GitLab pipeline pins every Preflight component to 0.3.0 (was 0.2.0). From 0.3.0 each component adds the Dependency Proxy prefix itself (`image_prefix`), so the `php-test`, `composer-audit` and `proctor` `image:` inputs are plain image names with the same tags and digests; CREED's own jobs keep the prefix. The GitHub workflow stays on 0.1.4. Best practices 23 and 42, the intro, the front page's clause 3.6 and the handbook's accessibility, code and secrets chapters name 0.3.0, and practice 23 and clause 3.6 say PROCTOR main's `preflight-pinned` accepts a full release tag as well as a commit SHA.
- Changed: the handbook catches up with two more decisions. The databases chapter's grace period says PROCTOR's `mysql-eol` warns and does not fail until Citadel offers a supported MySQL (D-053), and the runtime and headers chapter says the Cross-Origin-Resource-Policy default is settled as opt-in (HELMET 1.0.0 sends no header until the app sets one), so it leaves "Not decided here" and the front page's erratum E-7 keeps only the CSP report endpoints.
- Changed: the handbook catches up with three recorded decisions. The auth chapter states how the gateway's keys reach apps (one key set file with the current and the next key, read through SESAME's `gateway.jwks_file`, carried by Playbook) and how often they rotate (every 12 months, a retired key kept 5 minutes), says the `saml` driver is built on `onelogin/php-saml`, and takes the idle limits from `policy.json`'s `sessions` block (SESAME 120 minutes idle, 720 in all) instead of saying the stack sets none. The runtime and headers chapter gains a Metrics section (Prometheus format, off by default, the token never committed, the `sqlite` store on Citadel for now). The front page's erratum E-7 keeps only what is still open (the Cross-Origin-Resource-Policy default and the CSP report endpoints), the new E-8 shows the rest as settled, and backlog item 41 is done.
- Changed: every best-practice stamp, count and named file was re-checked against this tree, PROCTOR main, Preflight 0.2.0, HELMET and Rollcall on 2026-11-08. Practice 8 moves to Checked (the policy robot's first refresh landed on main), 53 to Checked (Preflight's `a11y` component), and 17, 23 and 34 to Partially (PROCTOR's `mysql-eol`, `preflight-pinned` and `crlf-deploy-script`). The tally is now Enforced 7, Checked 16, Partially 11, Documented 20, Not yet 7. Fixed with them: the Preflight pin (0.2.0, three components, at a tag), Preflight's `secret-detection` and `a11y` components where the text said none existed (practices 24, 41, 53, 55 and the handbook's secrets, accessibility and code chapters), the instance runner that is now in place (practice 36), and ten backlog items that had moved (seven done, three partly done). The front page's errata foot counts 42 backlog items (was 37), E-4 and E-5 show as settled, and a test now keeps the backlog count in step.
- Changed: the policy robot's docs (handbook versions chapter, patch refresh guide, proof page, practice 7) say it pushes a green refresh to main and opens a merge request only when its gate is red.
- Added: a test keeps public pages free of app counts (D-063): proof results stay on the private proof site, and public copy uses percentages and trends.
- Changed: the policy robot runs `composer ci` on the refreshed data in its own job and, when that passes, pushes the refresh straight to main with the CI job token (no merge request to merge by hand); a red gate pushes `auto/policy-refresh` as a merge request for review instead. The bot token is now only for the alert issues.
- Added: `policy.json` records session lifetimes (`sessions.sesame`: idle 120 and absolute 720 minutes; `sessions.gateway`: idle 3600 and session 28800 seconds).
- Docs: the handbook and two command descriptions write Rollcall and Squire as words, not in capitals.
- Fixed: the pipeline's PROCTOR jobs no longer pass `--policy`, which PROCTOR 0.7 refuses in CI; PROCTOR checks against the CREED policy it bundles.
- Changed: the GitLab pipeline pins the Preflight components to 0.2.0 (was 0.1.4).
- Changed: the front page's deploy layer (`deploy/`) runs Squire's kit 0.6.0 (was 0.5.0): `scripts/release.sh` asks PROCTOR's deploy gate for a go or no-go on each release (a report, not a stop, unless `SQUIRE_PROCTOR=enforce`), and `.env.squire.example` lists the new setting. The layer pins HELMET `^0.4` (locked 0.4.0).
- Fixed: the front page's authorities table says Sourdough starts all five app shapes, from raw PHP to Inertia and Vue; it no longer says raw PHP only.
- Changed: the front-page deploy layer pins PROCTOR ^0.7 (lock: PROCTOR 0.7.0, was 0.4.0).
- Changed: CI caches Composer downloads per lock file (`.composer-cache`, keyed on `composer.lock` and `deploy/composer.lock`), so a pipeline stops fetching every package from Packagist on every run. Images already come through the Dependency Proxy.

## 0.1.7 (2026-11-07)

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
- Changed: the docs site wears CREED's header, main menu, palette and fonts (docs/_header.html, docs/theme/creed.css and a theme key in docs/docs.json), light and dark, with callouts as stamped notes.
- Changed: README commands use the pinned Composer image.
- Added: decisions recorded: a 3 day Composer cooldown once Composer 2.11 is in the base images, even Node majors only, strict schema with tolerant readers, schema version 2 deferred.
- Added: a docs site (site/docs/) built by the shared docs builder from docs/, checked in composer ci.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Added: handbook chapter Runtime and headers (HELMET's headers, proxy trust, debug off in production).
- Added: handbook chapter Accessibility (WCAG 2.2 AA as the target, an automated check, the open Title II question).

## 0.1.6 (2026-11-03)

- Added: a policy-bot CI job (weekly schedule with CREED_JOB=policy-bot, or run by hand) that runs bin/policy-bot.php run with CREED_BOT_TOKEN; without the token it skips green. The check-only refresh-patches job now skips that schedule.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.

## 0.1.5 (2026-11-03)

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

## 0.1.4 (2026-10-30)

- Fixed: the bot's GitLab HTTP client failed on every API call (`stream_context_create` options were not under the `http` wrapper, a ValueError on current PHP). It now works, and reads response headers from the stream's metadata, so PHP 8.5 shows no deprecation. Found by Booster's hop-data robot, which uses this client.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## 0.1.3 (2026-10-29)

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- Changed: project names written as words except acronyms.
- Changed: README images show the current site and policy.
- Changed: README names which tools carry the reader copy; the auth chapter lists the `oidc` driver.
- Changed: the agent-ready kit is Squire 0.3.0's, so generated labels read "Squire's agent-ready kit".

- `bin/policy-bot.php run`: a real (non dry-run) run now commits the data
  allowlist on `auto/policy-refresh` and force-pushes it with GitLab merge
  request push options (create, target, remove source branch, label
  `auto-data`, title; `merge_request.auto_merge` on exit 3 only). The push
  URL comes from the CI_SERVER_* variables and CREED_BOT_TOKEN; missing
  variables stop the run with exit 1 naming them, and every printed line
  masks the token as `[masked]`. After an exit 0, a refresh whose
  `refreshed_on` is 7 or more days old runs the refresher again with
  `--touch` and treats that result like a normal run.

- `bin/policy-bot.php run`: after a handled real run the robot now keeps its
  GitLab alert issues in line with the report, one open issue per alert key
  (`held:<component>:<line>:<field>`, `refused:<component>:<line>:<field>`,
  `disagreement:<component>:<line>:<field>` while policy sides with neither
  source, and `upcoming-eol:<component>:<line>` within 60 days of
  `security_until`), each labelled `auto-alert` and titled `[auto-alert]
  <key>`. A key that is still present gets a note on its existing issue; a
  key that is gone is closed with a "Cleared on <date>." note. The client is
  built from CI_API_V4_URL, CI_PROJECT_ID and CREED_BOT_TOKEN; an incomplete
  environment stops the run with exit 1 naming the missing variables. A dry
  run prints the issues it would open and never calls the API.
- New `src/Bot/GitLabClient.php`: a small GitLab API client (list open
  issues, create an issue, add a note, close an issue) over an injectable
  `HttpTransport`, shipped with a dependency-free PHP streams transport
  (`GitLabClient::streamTransport`). The token rides only in the
  `PRIVATE-TOKEN` header, never in a URL, and every error it throws is
  masked, so a failed call can never print the token.
- Fixed: stale capability claims in the site and docs.

## 0.1.2 (2026-10-29)

- `bin/refresh-policy.php`: a released version that loosens a constraint now exits 3 (changes written) with an alert in the report, instead of exit 4.

- Fixed: a version of a known component that is older than the oldest listed
  line (PHP 5.x to 8.0, Laravel 5 to 8, Inertia 0.x) is now `Eol`, with the
  reason `older than the oldest listed line (8.1)` from the new
  `belowOldestLineReason($component, $version)`, instead of being refused as
  an unknown version. Unlisted components and version strings that do not
  parse are still refused, and a version above every listed line is still
  `Active`.

## 0.1.1 (2026-10-29)

- `bin/refresh-policy.php` keeps every support date, patch and new line in the
  policy tied to a canonical source (php.net, the Node.js release schedule,
  endoflife.date, Packagist, npm). Changes are classed tighten, neutral,
  loosen-warning or loosen-fail; a later security date is held until two
  sources agree or 14 days pass; nothing is ever removed. Provenance lives in
  the new `policy/sources.json` (schema and validation included), and the policy
  gains an optional `refreshed_on` date. `refresh-patches.php` keeps working.
- PHPStan now includes Pinstripe's raw preset
  (`vendor/waynestate/pinstripe/phpstan/raw.neon`) instead of carrying a hand
  copy; `phpstan.neon` keeps only this repo's own paths and phpVersion.
  `waynestate/pinstripe` joins require-dev (dev-main until 0.1.0 is tagged)
  and `phpstan/extension-installer` is allowed so the preset's deprecation
  rules load. The suit is off the rack now.
- `handbook/dependencies.md` gains a "Private packages" section: internal-only
  packages install from one GitLab registry per ecosystem, credentials stay
  out of repository URLs and lock files, builds use read-only deploy tokens,
  the private Composer entry is canonical, released versions never move, and
  public code goes to Packagist or npm. PROCTOR checks and the PREFLIGHT
  publish job enforce the rules. One standard, no cover versions.
- Policy lines may be marked `"current": true`: Active on every date, with no
  `security_until`. The schema rejects a current line that also carries a
  `security_until`, and a line that carries neither.
- `stateOf()` resolves a version to its policy line before judging it. A
  version that sorts above every listed line is `Active` (current versions
  are never refused), while a version between listed lines still throws.
- New reader method `isAheadOfPolicy($component, $version)`.
- `line()` reports a fifth field, `current` (true or null).
- The bundled policy lists Vue 3 and Inertia 2 as current lines.
- `bin/refresh-patches.php` reads the endoflife.date Vue feed and proposes new
  Vue lines (a current line, or EOL dates) alongside the patch refresh.
- The README's reader example is now a test: `tests/ReadmeTest.php` runs every
  ```php block that uses the reader against the bundled policy and asserts the
  values printed beside it. A snippet that cannot run as-is is skipped with a
  `<!-- readme-test: skip -->` comment.
- The handbook is now linted for RFC 2119 capitalization. `src/HandbookLinter.php`
  and `bin/lint-handbook.php` flag lowercase `must`, `must not`, `should`,
  `should not` and `may` in any chapter that declares RFC 2119, ignoring code
  spans and blocks; a same-line `<!-- lint: allow -->` excuses a deliberate
  plain-English use. `composer ci` runs the lint as `lint-handbook`.
- Every handbook chapter now declares RFC 2119 and uses capital keywords;
  `handbook/databases.md` gains a "Grace period" section spelling out the 90-day
  `eol_grace_days` window (`Security` on `security_until`, `Eol` for the window,
  `EolGraceExpired` after, no override).

## 0.1.0 (2026-10-27)

- First tagged release.
