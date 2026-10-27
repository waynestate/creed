# CREED best practices

Checked against live sources on 2026-10-27.

## What this covers

CREED is the written handbook for the Wayne State app stack, plus `policy/policy.json`, the one file that lists which
versions of PHP, Laravel, Node, MySQL and Vue are supported, with dates, and the rules every check runs against. This
guide is about two things: how WSU developers should treat versions and dependencies, and how CREED's maintainers
should keep the policy and the handbook trustworthy.

It is for WSU Enterprise Applications developers, and for whoever edits CREED. Creed Bratton keeps his own records and
nobody can audit them. CREED does the opposite: every date is in one file, with a source, where anyone can check it.

Each practice has a reason, sources, and a tag that says how the stack holds it today:

| Tag | Meaning |
|---|---|
| `Enforced` | Code makes the violation impossible, or a test fails CI. |
| `Checked` | PROCTOR or CI warns or fails. The check is named. |
| `Documented` | Written down only. |
| `Not yet` | A gap. |

Tags describe the repository and design documents as they stand on 2026-10-27. PROCTOR v0.1 is specified in
`first-repos.md` and its checks are named below as specified. CREED's own pipeline currently includes the
`php-test` and `composer-audit` components only, because the `proctor` component waits for a tagged CREED release.

## 1. The version policy as data

### 1. Keep one machine-readable policy, and let one reader decide state

Every tool reads `policy.json` through the CREED reader. No other code decides whether a version is active, security-only
or EOL.

Why: two tools with two copies of a date will eventually disagree, and a developer cannot tell which to believe.

Sources: [endoflife.date MySQL page](https://endoflife.date/mysql) (a public example of one table feeding many readers),
[JSON Schema introduction](https://json-schema.org/understanding-json-schema/about).

How the stack holds it: `Documented`. `Waynestate\Creed\Policy` is the only implementation, and ADR-0003 says no other
code decides state. Nothing stops another repo from re-implementing it. PROCTOR is meant to depend on the reader.

### 2. Validate the policy file against a schema in CI

`policy.json` is checked against `policy.schema.json` on every pipeline. A bad date, a wrong type or an unknown key
fails the build.

Why: a typo in a date silently changes what every tool reports.

Sources: [JSON Schema introduction](https://json-schema.org/understanding-json-schema/about).

How the stack holds it: `Enforced`. `PolicySchemaTest` validates the bundled file and rejects broken fixtures (bad
date, missing rules block, unknown key, string where an integer belongs). `composer validate-policy` runs the same
check. Both run in the `php-test` component.

### 3. Write dates as ISO 8601 days, never prose

Dates are `YYYY-MM-DD`. Quarters and phrases such as "Q3 2027" are not stored. When a project publishes only a quarter,
leave that date out until the project gives an exact day.

Why: tools compare dates as strings, and a vague date cannot be compared.

Sources: [JSON Schema introduction](https://json-schema.org/understanding-json-schema/about) (its date example),
[Laravel release notes](https://laravel.com/docs/13.x/releases) (shows a quarter for one date and exact days for others).

How the stack holds it: `Enforced`. The schema declares `format: date` and a fixture with a bad date is rejected.

### 4. Make the reader refuse to guess

A version with no `security_until` is an error, not a silent pass. An unknown component or version is an error.

Why: a tool that guesses turns missing data into a green check.

Sources: [GOV.UK Publishing manual, keeping software current](https://docs.publishing.service.gov.uk/manual/keeping-software-current.html)
(its policy is to track EOL dates explicitly).

How the stack holds it: `Enforced`. `stateOf` throws for unknown components, unknown versions and a missing
`security_until`; tests cover each case.

### 5. Compute state from the date, with an inclusive end and a stated grace period

A version is still supported on its `security_until` day. It is EOL the next day. After `eol_grace_days` (90) it is past
grace, and no override applies.

Why: everyone must get the same answer on the same day, including on the boundary.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php) (states end dates as days),
ADR-0003 (version policy).

How the stack holds it: `Enforced`. `PolicyStateTest` checks the PHP 8.2 boundary days and the oldest supported
version on 2026-10-27 and 2027-01-01.

### 6. Take every date from the project's own page, and record where and when

Copy each date from the vendor's page (php.net, Laravel release notes, the Node release schedule), not from memory or a
blog. Record the page and the day you checked.

Why: dates change, and a reviewer must be able to re-check one in a minute.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php),
[PHP EOL table](https://www.php.net/eol.php), [Laravel release notes](https://laravel.com/docs/13.x/releases),
[Node.js release schedule](https://github.com/nodejs/Release), [endoflife.date MySQL](https://endoflife.date/mysql).

How the stack holds it: `Documented`. `research/support-dates.md` lists the sources and the verification date. The
schema has no per-version `source` field, so the file itself does not carry the link.

### 7. Add each new release when it ships

When PHP, Laravel, Node or MySQL ships a supported release, add it to `policy.json` in the same week, so no tool meets a
version it cannot classify.

Why: a missing version is an error in the reader (practice 4), and it blocks the people who adopt it first.

Sources: [Laravel release notes](https://laravel.com/docs/13.x/releases),
[Node.js release schedule](https://github.com/nodejs/Release), [endoflife.date MySQL](https://endoflife.date/mysql).

How the stack holds it: `Not yet`. On 2026-10-27 `policy.json` lacks Laravel 13 (released 2026-03-17), Node 26 and
MySQL 9.7 LTS. See "Checks against the web" below.

### 8. Check the policy against upstream automatically

A scheduled job compares `policy.json` with a public EOL feed and opens a report when a date or a release differs.
The feed only checks; the dates in the file stay the source of truth.

Why: people forget; a job does not.

Sources: [endoflife.date MySQL](https://endoflife.date/mysql), [PHP supported versions](https://www.php.net/supported-versions.php).

How the stack holds it: `Not yet`. The prior-art note proposes using the endoflife.date API as a staleness check, but
nothing runs it.

### 9. Model support honestly when a project publishes no dates

Vue 3 and Inertia publish no fixed end dates. Do not invent them. Either extend the schema so a line can be "supported,
latest minor only", or leave the line out and document the rule.

Why: a made-up date is worse than an honest "no date".

Sources: [Vue release process](https://vuejs.org/about/releases) (no fixed schedule; minors every 3 to 6 months),
[Vue 2 end of life](https://v2.vuejs.org/eol/).

How the stack holds it: `Not yet`. `policy.json` lists only Vue 2 (EOL 2023-12-31) and an empty `inertia` list. The
reader requires `security_until`, so Vue 3 cannot be added as it stands.

### 10. Keep the policy a versioned contract that old tools can still read

Add fields; do not rename or remove them. Bump `schema_version` for breaking changes. An older PROCTOR must still read a
newer policy.

Why: tools ship on different days, and apps run old tool versions.

Sources: [Semantic Versioning 2.0.0](https://semver.org/).

How the stack holds it: `Documented`. ADR-0003 and `versions.md` state it. The schema pins `schema_version` to `1` and
the reader ignores unknown keys, but no test runs an older reader against a newer file.

## 2. PHP and framework versions

### 11. Run non-EOL PHP only

Apps declare and run a PHP version that php.net lists as supported. EOL PHP is not supported by the stack, with no
exceptions. On 2026-10-27 that means 8.2 (security only, to 2026-12-31), 8.3, 8.4 and 8.5.

Why: an EOL branch receives no security fixes.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php), [PHP EOL table](https://www.php.net/eol.php),
[GOV.UK Publishing manual, keeping software current](https://docs.publishing.service.gov.uk/manual/keeping-software-current.html),
[OWASP ASVS](https://asvs.owasp.org/).

How the stack holds it: `Checked`. PROCTOR `php-floor` fails when the lowest bound of `composer.json` `require.php` is
EOL by policy. Stack packages hold it at install time through their own `php` constraint (dependency floor, ADR-0002).

### 12. Treat security-only as a countdown, not a resting place

A version in security-only support warns now and fails on the day after `security_until`. Plan the move before then.

Why: moving on a deadline day is how upgrades go wrong.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php) (two years active, then two years of
security fixes), [GOV.UK Publishing manual, keeping software current](https://docs.publishing.service.gov.uk/manual/keeping-software-current.html).

How the stack holds it: `Checked`. `php-floor` reports a WARN for a security-only floor. The runtime ribbon and log
line in ADR-0003 wait for HELMET.

### 13. Raise the floor on the day the old version ends

The PHP floor moves from `^8.2` to `^8.3` on 2027-01-01. Stack packages raise their own `php` floor in a new major when
a PHP branch reaches EOL.

Why: it is the only way a dependency floor can force an upgrade.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php), [Semantic Versioning 2.0.0](https://semver.org/).

How the stack holds it: `Documented`. The README and `versions.md` state it, and `oldestSupported` computes it. No
automation bumps a package's constraint.

### 14. Match the framework release to a PHP version it supports

Laravel 12 supports PHP 8.2 to 8.5. Laravel 13 needs PHP 8.3 or newer. Pick the pair from the vendor table, not from
what happens to be installed.

Why: an app that cannot move PHP also cannot move Laravel, and the reverse.

Sources: [Laravel release notes](https://laravel.com/docs/13.x/releases).

How the stack holds it: `Not yet`. The policy does not record which PHP versions each Laravel release supports, and no
check compares them.

### 15. Run a Laravel release that still gets security fixes

Laravel gives bug fixes for 18 months and security fixes for two years after release. Laravel 11 and older are EOL.
Laravel 12 gets security fixes until 2027-02-24. Laravel 13, released 2026-03-17, gets them until 2028-03-17.

Why: an EOL framework is the largest unpatched surface in a typical app.

Sources: [Laravel release notes](https://laravel.com/docs/13.x/releases).

How the stack holds it: `Not yet`. The reader can classify Laravel 10, 11 and 12, but no PROCTOR v0.1 check reads
`composer.lock` for the framework. ADR-0006 plans it for the deploy gate.

### 16. Use a Node release that is in LTS or maintenance

Build front ends on a Node line that has not reached end of life. On 2026-10-27: 22 (to 2027-04-30), 24 (to
2028-04-30) and 26 (LTS from 2026-10-28, to 2029-04-30). Node 20 reached end of life on 2026-04-30.

Why: build tools run with your credentials and your source.

Sources: [Node.js release schedule](https://github.com/nodejs/Release).

How the stack holds it: `Not yet`. The dates for 20, 22 and 24 are in `policy.json`. No check reads an app's Node
version, and Node 26 is not listed.

### 17. Choose a supported MySQL, and say when the platform lags

MySQL 8.4 LTS has Premier support to 2029-04-30 and Extended to 2032-04-30. MySQL 9.7 LTS is newer. MySQL 8.0 ended on
2026-04-30. Versions 5.7 and 5.6 ended in 2023 and 2021.

Why: a database that no longer gets fixes holds everything above it back.

Sources: [endoflife.date MySQL](https://endoflife.date/mysql).

How the stack holds it: `Not yet`. `policy.json` lists MySQL 5.6, 5.7, 8.0 and 8.4, and the platform's own MySQL
timeline is an open decision (D-008). Nothing checks it.

## 3. Dependencies

### 18. Commit the lock file

`composer.lock` is in the repository for apps, so everyone installs the same versions. Use `composer install` in CI and
on deploy, not `composer update`.

Why: without a lock, a build next week is a different build.

Sources: [Composer basic usage](https://getcomposer.org/doc/01-basic-usage.md).

How the stack holds it: `Checked`. PROCTOR `lock-committed` fails when `composer.lock` is missing. ADR-0006 adds a
check that the lock matches `composer.json` at deploy time, which is not built.

### 19. Ban open-ended constraints

No `dev-*` branches and no `*` on runtime dependencies. Use caret constraints such as `^2.4`. Libraries use the same.

Why: an open constraint lets an unreviewed change into production.

Sources: [Composer versions article](https://getcomposer.org/doc/articles/versions.md),
[Composer CLI reference, `validate --strict`](https://getcomposer.org/doc/03-cli.md).

How the stack holds it: `Checked`. PROCTOR `unbounded-constraints` fails on `dev-*` and `*`, using `banned_constraints`
from `policy.json`.

### 20. Audit dependencies and fail at a stated severity

Run `composer audit --locked` in CI and fail at or above the policy threshold (`high`).

Why: known advisories are the cheapest attacks to run.

Sources: [Composer CLI reference, `audit`](https://getcomposer.org/doc/03-cli.md),
[OWASP ASVS](https://asvs.owasp.org/), [GOV.UK Publishing manual](https://docs.publishing.service.gov.uk/manual/keeping-software-current.html).

How the stack holds it: `Checked`. The PREFLIGHT `composer-audit` component runs in CI and PROCTOR `composer-audit`
reports against `audit_severity_threshold`. Note: Composer's own pages disagree on exit codes (see "Checks against the
web"), so wrappers should read the JSON output and not rely on the exit code alone.

### 21. Decide what to do about abandoned packages

Choose `ignore`, `report` or `fail` for abandoned packages and write it down. Composer's `--abandoned` flag and
`audit.abandoned` setting carry the choice.

Why: an abandoned package gets no fix when the next advisory lands.

Sources: [Composer CLI reference, `audit`](https://getcomposer.org/doc/03-cli.md).

How the stack holds it: `Not yet`. `policy.json` rules have no abandoned-package setting.

### 22. Keep the core free of framework dependencies

Each stack package requires only `php` and PSR interfaces. Laravel, Inertia and Vue are `suggest`. Adapters call the
core and never re-implement it.

Why: one rule in one place reaches every permutation, and an app is never forced onto a framework.

Sources: none outside the stack; this is ADR-0001. The [Composer versions article](https://getcomposer.org/doc/articles/versions.md)
explains the `^` constraints that make a PSR-only floor workable.

How the stack holds it: `Documented`. ADR-0001 and `dependencies.md` state it. The planned contract tests and example
apps are not built yet.

### 23. Pin CI components to a tag or SHA

Include shared CI components at a tag or a full commit SHA, never `~latest`.

Why: a moving reference lets an upstream change break or weaken your pipeline without a commit in your repo.

Sources: [Composer basic usage](https://getcomposer.org/doc/01-basic-usage.md) (the same reasoning as a lock file). The
pinning rule itself is the stack's own, from the first-repos design.

How the stack holds it: `Documented`. `ci.md` states it, and CREED's `.gitlab-ci.yml` pins both components to a SHA.
No check scans other repos.

## 4. Secrets and configuration

### 24. Keep secrets out of source control

Do not commit `.env`, keys, tokens or passwords. Commit `.env.example` with placeholders. Load real values from the
environment at deploy time. Rotate a secret that was ever exposed.

Why: a repository is copied to many machines and outlives any one password.

Sources: [OWASP Secrets Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html),
[Laravel configuration docs](https://laravel.com/docs/13.x/configuration).

How the stack holds it: `Not yet`. No handbook chapter covers secrets, and no check scans for committed secrets.

### 25. Read environment variables only in config files

In Laravel apps, call `env()` only from `config/*.php`, and read values elsewhere with `config()`. After
`config:cache`, `env()` outside config returns nothing.

Why: it works on a laptop and breaks in production.

Sources: [Laravel configuration docs](https://laravel.com/docs/13.x/configuration).

How the stack holds it: `Not yet`. The rule is named in design notes as a PHPStan rule planned for PROCTOR v0.2. It
belongs in Pinstripe and the handbook, and neither has it yet.

### 26. Never run production with debug on

`APP_DEBUG` is `false` in production.

Why: debug pages expose configuration values to users.

Sources: [Laravel configuration docs](https://laravel.com/docs/13.x/configuration).

How the stack holds it: `Not yet`. The runtime and headers chapter says "To be decided". HELMET's ribbon and refusal
rules are not built.

## 5. Overrides and rollout

### 27. Make every exception named, dated and visible

An override sits in the app's `policy-overrides.json` with a reason and an expiry. Every run prints every override. An
expired override fails.

Why: an exception without an end date becomes the rule.

Sources: [GOV.UK Publishing manual, keeping software current](https://docs.publishing.service.gov.uk/manual/keeping-software-current.html),
[University of Oregon applications and systems lifecycle procedure](https://service.uoregon.edu/TDClient/2030/Portal/KB/PrintArticle?ID=141020)
(read from a search summary only).

How the stack holds it: `Checked`. PROCTOR `overrides` prints every override and fails an expired one. ROLLCALL is
meant to list them across apps and is not built.

### 28. Roll a new rule out in stages, with dates

Each rule moves `report`, then `warn`, then `block`, with the dates written in `policy.json`. A deploy that does not make
things worse always passes.

Why: blocking every non-compliant app on day one blocks the fixes too.

Sources: [GOV.UK Publishing manual, keeping software current](https://docs.publishing.service.gov.uk/manual/keeping-software-current.html),
ADR-0006 (Citadel deploy gate).

How the stack holds it: `Documented`. ADR-0006 is Proposed and needs the Citadel team. The schema has no field for
stage dates.

### 29. Name where each rule is enforced

Every rule says which point holds it: runtime, dependency floor, CI, Citadel deploy, or fleet view. Prefer the earliest
point that cannot be skipped.

Why: a rule that nothing runs is only a wish.

Sources: [OWASP ASVS](https://asvs.owasp.org/).

How the stack holds it: `Documented`. ADR-0002 defines the five points. The handbook chapters do not tag each rule
with its point or its PROCTOR check name.

## 6. Writing the handbook

### 30. Cite the decision behind every rule

Each handbook rule links to the ADR that decided it. Where nothing is decided, the chapter says "To be decided" and
states no rule.

Why: readers can trust a rule, and can find who to ask about the rest.

Sources: [Architectural Decision Records](https://adr.github.io/).

How the stack holds it: `Documented`. Every chapter in `handbook/` follows it. `accessibility.md` and
`runtime-and-headers.md` are still placeholders.

### 31. Record open questions with an owner

Undecided matters go in `DECISIONS.md` with who can answer, and become an ADR when decided.

Why: an open question with no owner stays open.

Sources: [Architectural Decision Records](https://adr.github.io/).

How the stack holds it: `Documented`. `design/DECISIONS.md` follows the format.

### 32. Link each rule to the command that measures it

Write the rule, then the PROCTOR check ID that tests it, or say plainly that none exists.

Why: a standard is a command that fails, not a sentence in a README.

Sources: [OWASP ASVS](https://asvs.owasp.org/) (requirements written to be verified).

How the stack holds it: `Not yet`. The handbook does not name check IDs.

### 33. Judge with deterministic checks only

No gate depends on a language model's opinion. A model may explain a failure; it does not decide pass or fail.

Why: a gate must give the same answer twice.

Sources: none outside the stack; this is ADR-0007.

How the stack holds it: `Documented`. ADR-0007 and `ci.md` state it. The checks are deterministic by design.

### 34. Keep every developer tool usable on Windows, macOS and Linux

Scripts ship as a `.ps1` and `.sh` pair, or as one PHP command. Every repo carries `.gitattributes` with
`* text=auto eol=lf`.

Why: developers work on all three, and Citadel needs LF.

Sources: none outside the stack; this is ADR-0009.

How the stack holds it: `Documented`. ADR-0009 and `every-os.md` state it, and CREED has the `.gitattributes`.
PROCTOR checks for CRLF in server files and case collisions are planned, not in v0.1.

### 35. Run the test suite on every supported PHP version

CI runs the unit tests on 8.2, 8.3, 8.4 and 8.5, and the floor moves with the policy.

Why: a library that works only on the newest PHP excludes half its users.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php).

How the stack holds it: `Checked`. The `php-test` component runs a matrix, and CREED's pipeline includes it.

### 36. Recommend a git.wayne.edu instance runner

CI is a gate only if something runs it. Every product states the recommendation.

Why: a gate that nothing runs protects nobody.

Sources: [GitLab Code Quality docs](https://docs.gitlab.com/ci/testing/code_quality/) (the report is produced by a
pipeline job, so it needs a runner).

How the stack holds it: `Documented`. The README and `ci.md` state it; the runner is not in place.

## Checks against the web

These are places where a source disagrees with the repository or with an earlier note.

- **Laravel 13 is missing from `policy.json`.** The Laravel page lists 13 as released on 2026-03-17, with bug fixes to
  Q3 2027 and security fixes to 2028-03-17, and PHP 8.3 to 8.5. ADR-0003 says "Q1 2028"; the page now gives the day.
- **Node 26 is missing.** The Node schedule lists 26 with LTS from 2026-10-28, maintenance from 2027-10-20 and end of
  life on 2029-04-30. The dates for Node 20, 22 and 24 in `policy.json` match.
- **MySQL 9.7 LTS is missing.** endoflife.date lists it with Premier to 2031-04-30 and Extended to 2034-04-30. The
  dates for 5.6, 5.7, 8.0 and 8.4 match.
- **PHP dates match.** 8.1 to 8.5 agree with php.net and the EOL table.
- **Vue 3 cannot be listed.** Its release page gives no fixed end dates, and the reader needs `security_until`. Vue 2 is
  correct (EOL 2023-12-31). One secondary search result said Vue 3.6 was released in July 2026; another showed it as a
  release candidate in October. This was not settled from an official source.
- **Composer audit exit codes conflict.** The CLI reference lists 0 for clean and 1 for findings. The 2.8.4 changelog
  says 1 for vulnerabilities, 2 for abandoned packages and 3 for both. Pin the Composer version in CI and test the
  behaviour.
- **Policy "active" for Node and MySQL is a mapping.** `active_until` holds Node's maintenance start and MySQL's Premier
  end. This is a reasonable mapping and is not the vendors' own wording.

## Gaps for the backlog

1. Add Laravel 13, Node 26 and MySQL 9.7 to `policy.json` (practice 7).
2. Add a scheduled freshness check against a public EOL feed (practice 8).
3. Extend the schema for lines with no end date, so Vue 3 and Inertia can be listed (practice 9).
4. Add a `source` and `checked_on` field to each version entry (practice 6).
5. Record the supported PHP range per Laravel release, and add a PROCTOR check for the pair (practice 14).
6. Add a PROCTOR check that reads the Laravel version from `composer.lock` against the policy (practice 15).
7. Add a PROCTOR check for the Node version in `.nvmrc` or `package.json` engines (practice 16).
8. Add a MySQL check or report once the platform timeline is known (practice 17).
9. Add an abandoned-package setting to `rules` and apply it in the audit check (practice 21).
10. Add a secrets chapter, and a secret-scanning job in PREFLIGHT (practice 24).
11. Add a PHPStan rule for `env()` outside `config/` and put the rule in Pinstripe (practice 25).
12. Add a `config` check that `APP_DEBUG` is false in production, and write the runtime and headers chapter (practice 26).
13. Put the PROCTOR check ID next to every handbook rule (practices 29 and 32).
14. Add a test that an older reader accepts a newer policy file (practice 10).
15. Add stage dates (`report`, `warn`, `block`) to the schema (practice 28).
16. Add PROCTOR checks for `.gitattributes`, CRLF in server files and file-name case collisions (practice 34).
17. Add a PROCTOR check that CI includes are pinned to a tag or SHA (practice 23).
18. Write the accessibility chapter once the rules are decided (practice 30).

## Sources

All checked on 2026-10-27.

- PHP, supported versions: https://www.php.net/supported-versions.php
- PHP, unsupported branches: https://www.php.net/eol.php
- Laravel 13 release notes and support policy: https://laravel.com/docs/13.x/releases
- Laravel 13 configuration: https://laravel.com/docs/13.x/configuration
- Node.js release schedule: https://github.com/nodejs/Release
- endoflife.date, MySQL: https://endoflife.date/mysql
- Vue release process: https://vuejs.org/about/releases
- Vue 2 end of life: https://v2.vuejs.org/eol/
- Composer, basic usage: https://getcomposer.org/doc/01-basic-usage.md
- Composer, versions and constraints: https://getcomposer.org/doc/articles/versions.md
- Composer, command-line reference (`audit`, `validate`): https://getcomposer.org/doc/03-cli.md
- Composer 2.8.4 changelog: https://getcomposer.org/changelog/2.8.4
- GOV.UK Publishing manual, keeping software current (page says it may be out of date; last updated 2025-03-19):
  https://docs.publishing.service.gov.uk/manual/keeping-software-current.html
- University of Oregon, systems and applications lifecycle procedure (read from a search summary, not the page):
  https://service.uoregon.edu/TDClient/2030/Portal/KB/PrintArticle?ID=141020
- OWASP Secrets Management Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html
- OWASP Application Security Verification Standard (current version 5.0.0, 2025-05-30): https://asvs.owasp.org/
- Semantic Versioning 2.0.0: https://semver.org/
- JSON Schema, introduction: https://json-schema.org/understanding-json-schema/about
- Architectural Decision Records: https://adr.github.io/
- GitLab Code Quality: https://docs.gitlab.com/ci/testing/code_quality/
