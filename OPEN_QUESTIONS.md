# Open questions

What CREED does not know yet, before 1.0. Each entry says what is unknown, what CREED ships meanwhile, who can answer,
and what changes when they do. The front page prints the same list as its errata slip (E-6 onward), and the backlog
at the end of `BEST_PRACTICES.md` keeps the longer history.

Creed Bratton answers every question he is asked, usually with a story about the sixties. This file only answers the
ones it can source, and leaves the rest open in plain sight.

## Waiting on the maintainers

These are numbers the stack's maintainers choose. CREED ships the data model, so the answer is one edit to
`policy/policy.json` plus a CHANGELOG line, and every reader picks it up on its next refresh.

1. **The oldest acceptable patch per PHP line** (practice 37, backlog item 19, front page E-9). `policy.json` can carry
   a `minimum_patch` per PHP line, read by `Policy::minimumPatch()` and checked by `bin/validate-policy.php`, but no
   line sets one. Meanwhile the reader answers null and nothing fails on an old patch; `latest_patch` still shows how
   far behind an image is. Changes: set a floor (for example the patch that fixed the last critical advisory) and
   PROCTOR can compare an image or runtime against it. Leaving every line empty is also an answer; say so here.
2. **How fast a fix must ship, per advisory severity** (practice 38, backlog item 20, front page E-10). `composer
   audit` finds advisories, and `audit_severity_threshold` (high) decides which ones fail CI. `policy.json` can carry
   `rules.fix_within_days` (days per `critical`, `high`, `medium` and `low`), read by `Policy::fixWithinDays()`, but
   no severity has a number. Meanwhile the reader answers null and there is no stated window. Changes: the maintainers
   pick N per severity, and a check (PROCTOR, against audit output) can apply it.
3. **The longest an override may run** (practice 27, front page E-11). `rules.override_max_days` is 90, the same
   number as `eol_grace_days`, and PROCTOR fails a longer override. Meanwhile 90 holds. Changes: confirm 90, or name
   another number; it is one value in `policy.json`.

## Waiting on someone outside the stack

1. **Which ADA Title II dates apply** (practices 52 and 54, backlog item 34, front page E-6). The accessibility
   chapter aims at WCAG 2.2 AA and Preflight's `a11y` component holds every page to it. Which compliance dates apply to
   a given WSU unit is for WSU's counsel and accessibility office. Changes: the chapter names the dates and the level
   they require.
2. **git.wayne.edu's GitLab patch level and routine** (practice 45, backlog item 27, front page E-12). git.wayne.edu
   runs GitLab 19.5.1 Community Edition, read from the instance itself. Its owners have not published a patch routine.
   Meanwhile CREED treats the CI host as part of the supply chain and says nothing about its schedule. Changes: a
   published patch level and routine, which practice 45 can then cite.
3. **When the platform adds a PHP image, and how it retires one** (practices 44 and 48, backlog items 26 and 30, front
   page E-13). `images.docker` is filled for PHP 8.3 to 8.5; `images.citadel` waits for Citadel's image names, and no
   target says how soon a new PHP image arrives or how much notice comes before one is dropped. Changes: Citadel's
   image names fill `images.citadel`, and a stated target and notice period go into `handbook/versions.md`.
4. **The shared MySQL 5.6 server's replacement timeline** (practices 17 and 47, backlog item 8, front page E-14). PROCTOR's
   `mysql-eol` grades the MySQL an app names, warning only, and Preflight's `mysql56-migrate` runs migrations on 5.6.
   A platform report waits for the timeline. Changes: the report, and a date when 5.6 stops being a CI target.
5. **The deploy gate in Citadel's release hook** (practice 28, `handbook/deploy-gate.md`, front page E-15). The
   handbook proposes `proctor gate` in the release hook, against the fetched commit, before checkout; the Citadel team
   has not adopted it. Meanwhile PROCTOR runs in CI and the gate's stages wait in `policy.json`'s `gate` block (all
   `block`, none staged). Changes: the hook runs the gate, and new checks start at `report`.
6. **Where Content Security Policy reports go** (front page E-7). HELMET adds `report-uri`, `report-to` and a
   `Reporting-Endpoints` header when an app sets `HELMET_CSP_REPORT_URI`, but the stack has no shared endpoint to
   point it at. Meanwhile each app chooses its own, or none. Changes: the runtime and headers chapter names one.

## How to close one

Answer it in an issue on git.wayne.edu, or in a merge request that changes the file the entry names. The merge request
that closes it deletes the entry here, moves the front page's errata row to Settled with the date, and adds a CHANGELOG
line.
