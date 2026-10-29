---
title: "CREED: The standard everything rests on."
description: "CREED is the Wayne State app stack's written standard: 54 numbered practices, policy.json with the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, and a small PHP reader that tells a tool what state a version is in on a given day."
url: https://creed.apps.wayne.edu/
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Wayne State App Stack 2026 Edition Adopted 2026-10-29

# CREED The Wayne State App Stack Code

§0.1 Short title

This code shall be known as CREED, which stands for

C&IT Rules for Every Enterprise Developer

and may be cited as *the standard everything rests on*.

These are the stack's written standards. The handbook has the practices, and `policy.json` has the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia. A small PHP reader takes a version and a day and tells you its state, so PROCTOR, Rollcall and the rest don't each keep their own list of dates.

**Already included in [WELD](https://weld.apps.wayne.edu/).** Install CREED by itself when an app or tool that isn't WELD needs the stack's rules or the dates in `policy.json`.

[Inspect a version](https://creed.apps.wayne.edu/#inspection) [Read the handbook](https://creed.apps.wayne.edu/handbook/)

- **Enforced**: 5
- **Checked**: 7
- **Documented**: 14
- **Not yet**: 28

Table of contents

## Ten chapters, 54 practices

Each practice has a reason, its sources and a stamp for how the stack holds it today. Clause numbers follow the chapters, so §2.1 is practice 11 in [BEST_PRACTICES.md](https://github.com/waynestate/creed/blob/main/BEST_PRACTICES.md). This page prints the clauses people ask about most. All 54 are in that file, and the rules themselves are in [the handbook](https://creed.apps.wayne.edu/handbook/).

1. [1 The version policy as data §1.1 to §1.10](https://creed.apps.wayne.edu/#ch-1)
2. [2 PHP and framework versions §2.1 to §2.7](https://creed.apps.wayne.edu/#ch-2)
3. [3 Dependencies §3.1 to §3.6](https://creed.apps.wayne.edu/#ch-3)
4. [4 Secrets and configuration §4.1 to §4.3](https://creed.apps.wayne.edu/#ch-4)
5. [5 Overrides and rollout §5.1 to §5.3](https://creed.apps.wayne.edu/#ch-5)
6. [6 Writing the handbook §6.1 to §6.7](https://creed.apps.wayne.edu/#ch-6)
7. [7 Patching and the supply chain §7.1 to §7.9](https://creed.apps.wayne.edu/#ch-7)
8. [8 Platform and database §8.1 to §8.3](https://github.com/waynestate/creed/blob/main/BEST_PRACTICES.md#8-platform-and-database-second-pass)
9. [9 Policy data §9.1 to §9.3](https://github.com/waynestate/creed/blob/main/BEST_PRACTICES.md#9-policy-data-second-pass)
10. [10 Accessibility and the legal floor §10.1 to §10.3](https://github.com/waynestate/creed/blob/main/BEST_PRACTICES.md#10-accessibility-and-the-legal-floor-second-pass)

### Key to the stamps

- **Enforced**: Code makes the violation impossible, or a test fails CI.
- **Checked**: PROCTOR or CI warns or fails. The check is named.
- **Documented**: Written down only.
- **Not yet**: A gap. We print it anyway so you know it's there.

Art. I

### The policy

`policy/policy.json` holds every support date as an ISO day, plus the rules: an audit threshold of `high`, banned constraints `dev-*` and `*`, and a grace of 90 days. A schema guards it.

Art. II

### The reader

`Waynestate\Creed\Policy` is plain PHP with no framework. `stateOf` returns `Active`, `Security`, `Eol` or `EolGraceExpired`. No other code in the stack decides a version's state.

Art. III

### The handbook

[The handbook](https://creed.apps.wayne.edu/handbook/) has the rules, one chapter per topic, and each rule links the decision behind it. Where we haven't decided yet, the chapter says "To be decided" and gives no rule.

Form CR-1 · Field inspection

## Inspect a version

Pick a runtime, a version and a day. The form uses the same dates and rules as `Policy::stateOf`: a version is still supported on its last day, then it gets 90 days of grace. After that, no override helps. The inspector stamps what he finds.

Certificate of inspection

No. 0001

- **Runtime**: PHP 8.2
- **Inspected on**: 2026-10-29
- **Reader call**: `$policy->stateOf('php', '8.2', new DateTimeImmutable('2026-10-29'))`
- **Returns**: `State::Security`

Security fixes only, until 2026-12-31: 63 days left. Plan the move to PHP 8.4 now.

Applicable clause: [§2.2 Treat security-only as a countdown, not a resting place](https://creed.apps.wayne.edu/#c-2-2)

Inspector CREED reader, schema v1

Chapter 1

## The version policy as data

Creed Bratton keeps his own records and nobody can audit them. CREED does the opposite: every date is in one file, with a source, where anyone can check it.

### §1.1 Keep one machine-readable policy, and let one reader decide state.

Every tool reads `policy.json` through the CREED reader. No other code decides whether a version is active, security-only or EOL.

Held by `Waynestate\Creed\Policy`, the only implementation. [PROCTOR](https://creed.apps.wayne.edu/#ahj-proctor), Booster and Rollcall each carry a generated copy, and `bin/export.php --check` fails when a copy drifts.

Documented

**Rationale.** Two tools with two copies of a date will eventually disagree, and a developer cannot tell which to believe.

### §1.2 Validate the policy file against a schema in CI.

A bad date, a wrong type or an unknown key fails the build.

Held by `PolicySchemaTest` and `composer validate-policy`, both in [Preflight](https://creed.apps.wayne.edu/#ahj-preflight) `php-test`.

Enforced

**Rationale.** A typo in a date silently changes what every tool reports.

### §1.4 Make the reader refuse to guess.

A version with no `security_until` is an error, not a silent pass, unless its line is marked current. An unknown component, or a version between the listed lines, is an error. A version older than the oldest listed line is EOL. A version newer than every listed line is Active: current versions are never refused.

Held by `stateOf`, which throws for each error case, and `isAheadOfPolicy`; tests cover every one.

Enforced

**Rationale.** A tool that guesses turns missing data into a green check.

### §1.5 Compute state from the date, with an inclusive end and a stated grace period.

A version is still supported on its `security_until` day. It is EOL the next day. After `eol_grace_days` (90) it is past grace, and no override applies.

Held by `PolicyStateTest`, on the PHP 8.2 boundary days.

Enforced

**Rationale.** Everyone must get the same answer on the same day, including on the boundary. *With arms wide open*: the window stays open through the last day.

### §1.9 Model support honestly when a project publishes no dates.

Vue 3 and Inertia publish no fixed end dates. Do not invent them. The policy marks Vue 3, Inertia 2 and Inertia 3 `"current": true`: Active on every date, with no `security_until`. Vue 2 keeps its real end, 2023-12-31.

Held by the schema, which refuses a current line that carries `security_until` and a dated line without it; `PolicySchemaTest` covers both.

Enforced

**Rationale.** A made-up date is worse than an honest "no date".

Chapter 2

## PHP and framework versions

EOL PHP is not supported by the stack, with no exceptions. The adopted editions are in Table 2-1.

### §2.1 Run non-EOL PHP only.

Apps declare and run a PHP version that php.net lists as supported. On 2026-10-29 that means 8.2 (security only, to 2026-12-31), 8.3, 8.4 and 8.5.

Held by [PROCTOR](https://creed.apps.wayne.edu/#ahj-proctor) `php-floor`, which fails when the lowest bound of `require.php` is EOL by policy.

Checked

**Rationale.** An EOL branch receives no security fixes.

### §2.2 Treat security-only as a countdown, not a resting place.

A version in security-only support warns now and fails on the day after `security_until`. Plan the move before then.

Held by [PROCTOR](https://creed.apps.wayne.edu/#ahj-proctor) `php-floor` (a WARN). [HELMET](https://creed.apps.wayne.edu/#ahj-helmet) ships the runtime ribbon.

Checked

**Rationale.** Moving on a deadline day is how upgrades go wrong.

### §2.3 Raise the floor on the day the old version ends.

The PHP floor for apps moves from `^8.2` to `^8.3` on 2027-01-01. CREED itself already requires `^8.3`; Creed has always been one version ahead of everyone else, at least by his own account.

Held by `oldestSupported` computes it. No automation bumps a package's constraint.

Documented

**Rationale.** It is the only way a dependency floor can force an upgrade.

Table 2-1 Adopted editions, with the state on 2026-10-29

| Runtime | Active until | Security until | Latest patch | State |
| --- | --- | --- | --- | --- |
| PHP floor **8.2** |  |  |  |  |
| 8.5 | 2027-12-31 | 2029-12-31 | 8.5.12 | Active |
| 8.4 | 2026-12-31 | 2028-12-31 | 8.4.27 | Active |
| 8.3 | 2025-12-31 | 2027-12-31 | 8.3.36 | Security |
| 8.2 | 2024-12-31 | 2026-12-31 | 8.2.35 | Security |
| 8.1 | 2023-11-25 | 2025-12-31 | n/a | Grace expired |
| Laravel floor **12** |  |  |  |  |
| 13 | 2027-09-30 | 2028-03-17 | 13.39.0 | Active |
| 12 | 2026-08-13 | 2027-02-24 | 12.70.2 | Security |
| 11 | 2025-09-03 | 2026-03-12 | 11.57.1 | Grace expired |
| 10 | 2024-08-06 | 2025-02-04 | 10.50.4 | Grace expired |
| Node floor **22** |  |  |  |  |
| 26 | 2027-10-20 | 2029-04-30 | 26.13.0 | Active |
| 24 | 2026-10-20 | 2028-04-30 | 24.22.2 | Security |
| 22 | 2025-10-21 | 2027-04-30 | 22.23.4 | Security |
| 20 | 2024-10-22 | 2026-04-30 | 20.20.2 | Grace expired |
| MySQL floor **8.4** |  |  |  |  |
| 9.7 | 2031-04-30 | 2034-04-30 | n/a | Active |
| 8.4 | 2029-04-30 | 2032-04-30 | n/a | Active |
| 8.0 | 2025-04-30 | 2026-04-30 | n/a | Grace expired |
| 5.7 | 2020-10-31 | 2023-10-31 | n/a | Grace expired |
| 5.6 | 2018-02-28 | 2021-02-28 | n/a | Grace expired |
| Vue and Inertia |  |  |  |  |
| Vue 2 | 2023-12-31 | 2023-12-31 | n/a | Grace expired |
| Vue 3 | Current line: no published end dates, none invented; see §1.9. | Active |  |  |
| Inertia 2 | Current line: no published end dates, none invented; see §1.9. | Active |  |  |
| Inertia 3 | Current line: no published end dates, none invented; see §1.9. | Active |  |  |

From `policy/policy.json`, schema version 1, patches checked 2026-10-28 and 2026-10-29. For Node, "active" holds the maintenance start; for MySQL, the Premier end. That mapping is the policy's, not the vendors' wording.

### §2.5 Run a Laravel release that still gets security fixes.

Laravel gives bug fixes for 18 months and security fixes for two years. Laravel 11 and older are EOL. Laravel 12 gets security fixes until 2027-02-24; Laravel 13 until 2028-03-17.

Gap The reader can classify 10 to 13. PROCTOR `unpatched` reads `laravel/framework` from `composer.lock` and warns when the locked patch trails the policy's `latest_patch`, but no check fails an app whose framework line is EOL; the planned deploy gate will.

Not yet

**Rationale.** An EOL framework is the largest unpatched surface in a typical app.

### §2.6 Use a Node release that is in LTS or maintenance.

Build front ends on a Node line that has not reached end of life: 22, 24 and 26. Node 20 reached end of life on 2026-04-30.

Gap The dates are in `policy.json`; no check reads an app's Node version yet.

Not yet

**Rationale.** Build tools run with your credentials and your source.

### §2.7 Choose a supported MySQL, and say when the platform lags.

MySQL 8.4 LTS has Premier support to 2029-04-30 and Extended to 2032-04-30. MySQL 8.0 ended on 2026-04-30 and is past CREED's 90-day grace since 2026-07-29.

Gap Nothing checks it, and the platform's own MySQL timeline is still an open question.

Not yet

**Rationale.** A database that no longer gets fixes holds everything above it back.

Chapter 3

## Dependencies

What goes into an app, and how it is allowed in.

### §3.1 Commit the lock file.

`composer.lock` is in the repository for apps. Use `composer install` in CI and on deploy, not `composer update`.

Held by [PROCTOR](https://creed.apps.wayne.edu/#ahj-proctor) `lock-committed`.

Checked

**Rationale.** Without a lock, a build next week is a different build.

### §3.2 Ban open-ended constraints.

No `dev-*` branches and no `*` on runtime dependencies. Use caret constraints such as `^2.4`.

Held by [PROCTOR](https://creed.apps.wayne.edu/#ahj-proctor) `unbounded-constraints`, using `banned_constraints` from the policy.

Checked

**Rationale.** An open constraint lets an unreviewed change into production.

### §3.3 Audit dependencies and fail at a stated severity.

Run `composer audit --locked` in CI and fail at or above the policy threshold, `high`.

Held by [Preflight](https://creed.apps.wayne.edu/#ahj-preflight) `composer-audit` and [PROCTOR](https://creed.apps.wayne.edu/#ahj-proctor) `composer-audit`.

Checked

**Rationale.** Known advisories are the cheapest attacks to run.

### §3.6 Pin CI components to a tag or SHA.

Include shared CI components at a tag or a full commit SHA, never `~latest`. CREED's own pipeline pins both of its components to a SHA.

Held by `ci.md`; components from [Preflight](https://creed.apps.wayne.edu/#ahj-preflight). No check scans other repos.

Documented

**Rationale.** A moving reference lets an upstream change weaken your pipeline without a commit in your repo.

Chapter 4

## Secrets and configuration

None of the three practices in this chapter is held yet. Two of them are printed below, gaps and all.

### §4.1 Keep secrets out of source control.

Do not commit `.env`, keys, tokens or passwords. Commit `.env.example` with placeholders. Rotate a secret that was ever exposed.

Gap No handbook chapter covers secrets, and no check scans for them yet.

Not yet

**Rationale.** A repository is copied to many machines and outlives any one password.

### §4.3 Never run production with debug on.

`APP_DEBUG` is `false` in production.

Gap The runtime and headers chapter says "To be decided"; [HELMET](https://creed.apps.wayne.edu/#ahj-helmet)'s refusal rules are not built.

Not yet

**Rationale.** Debug pages expose configuration values to users.

Chapter 5

## Overrides and rollout

Every code has variances. These are the rules for asking for one.

### §5.1 Make every exception named, dated and visible.

An override sits in the app's `policy-overrides.json` with a reason and an expiry. Every run prints every override. An expired override fails.

Held by [PROCTOR](https://creed.apps.wayne.edu/#ahj-proctor) `overrides`. [Rollcall](https://creed.apps.wayne.edu/#ahj-rollcall) counts expired overrides across apps in its fleet report.

Checked

**Rationale.** An exception without an end date becomes the rule.

### §5.2 Roll a new rule out in stages, with dates.

Each rule moves `report`, then `warn`, then `block`. A deploy that does not make things worse always passes.

Held by the proposed deploy gate. The schema has no field for stage dates yet.

Documented

**Rationale.** Blocking every non-compliant app on day one blocks the fixes too.

Chapter 6

## Writing the handbook

The rules for writing CREED itself. Mostly: link the decision, and don't let a gate depend on anyone's opinion.

### §6.1 Cite the decision behind every rule.

Each handbook rule links to the ADR that decided it. Where nothing is decided, the chapter says "To be decided" and states no rule.

Documented

**Rationale.** Readers can trust a rule, and can find who to ask about the rest.

### §6.4 Judge with deterministic checks only.

No gate depends on a language model's opinion. A model may explain a failure; it does not decide pass or fail.

Documented

**Rationale.** A gate must give the same answer twice.

### §6.5 Keep every developer tool usable on Windows, macOS and Linux.

Scripts ship as a `.ps1` and `.sh` pair, or as one PHP command. Every repo carries `.gitattributes` with `* text=auto eol=lf`.

Documented

**Rationale.** Developers work on all three, and the servers need LF.

### §6.6 Run the test suite on every supported PHP version.

CI runs CREED's unit tests on 8.3, 8.4 and 8.5, from its own floor up.

Held by [Preflight](https://creed.apps.wayne.edu/#ahj-preflight) `php-test`, a matrix in CREED's pipeline.

Checked

**Rationale.** A library that works only on the newest PHP excludes half its users.

Chapter 7 · Added in the second pass

## Patching and the supply chain

Nine practices, one held so far. Chapters 8 to 10 (platform and database, policy data, accessibility) are all "Not yet" and are in the book.

### §7.1 Track the PHP patch release, not only the branch.

A supported branch is not a patched one. On 2026-10-21 PHP released 8.5.12, 8.4.27, 8.3.36 and 8.2.35 to fix two critical advisories. `policy.json` now carries a `latest_patch` for each branch.

Held by `latestPatch()` in the reader; maintainers refresh it with `php bin/refresh-patches.php`.

Documented

**Rationale.** The branch tells you whether fixes still come; the patch tells you whether you took them.

Inserted after printing

## Errata and pending amendments

The questions the 2026 edition hasn't answered. Each one names the clause it affects. It stays open until we make a decision or find a source.

| No. | Clause | Erratum or amendment | Status |
| --- | --- | --- | --- |
| E-4 | §9.3 | Strict or tolerant schema: can an older tool still read a newer policy that only added a field? A test with an old schema and reader decides. | Open |
| E-5 | §9.1 | Node 27 and later have no Maintenance phase on the new yearly schedule, so how `active_until` is derived needs a new rule. | Open |
| E-6 | Ch. 10 | The accessibility chapter: aim at WCAG 2.2 AA, after confirming which legal dates apply. To be decided. | Open |
| E-7 | Handbook | Runtime and headers: no rules until a decision lands with HELMET. The auth chapter still has the gateway's key distribution, and the SAML library if a SAML driver is built, to decide. | Open |

The full backlog, 37 items, is at the end of [BEST_PRACTICES.md](https://github.com/waynestate/creed/blob/main/BEST_PRACTICES.md#gaps-for-the-backlog).

Referenced tools

## Authorities having jurisdiction

CREED writes the rules down. These are the other projects in the stack, and the clauses each one holds. PROCTOR, Booster and Rollcall read `policy.json` through a generated copy of the reader, and ADVISOR carries the policy and the handbook as data.

- ![WayneUI logo: a rounded frame with a gold tab](https://creed.apps.wayne.edu/assets/marks/wayneui.svg)[WayneUI](https://wayneui.apps.wayne.edu/)Wayne State's design system, project 0 of the stack.This page is built on it
- ![CREED logo: a stone arch with a gold keystone](https://creed.apps.wayne.edu/brand/creed-avatar-256.png)CREEDThe standards, with the policy file and its reader. This book.Chapters 1 to 10
- ![PROCTOR logo: an eye with a gold square pupil](https://creed.apps.wayne.edu/assets/marks/proctor.png)[PROCTOR](https://proctor.apps.wayne.edu/)Reads your `composer.json` and lock file and checks them against CREED.[§2.1](https://creed.apps.wayne.edu/#c-2-1) [§2.2](https://creed.apps.wayne.edu/#c-2-2) [§3.1](https://creed.apps.wayne.edu/#c-3-1) [§3.2](https://creed.apps.wayne.edu/#c-3-2) [§3.3](https://creed.apps.wayne.edu/#c-3-3) [§5.1](https://creed.apps.wayne.edu/#c-5-1)
- ![Preflight logo: a runway with a gold centre line](https://creed.apps.wayne.edu/assets/marks/preflight.png)[Preflight](https://preflight.apps.wayne.edu/)GitLab CI/CD components that run the checks on every push.[§1.2](https://creed.apps.wayne.edu/#c-1-2) [§3.3](https://creed.apps.wayne.edu/#c-3-3) [§3.6](https://creed.apps.wayne.edu/#c-3-6) [§6.6](https://creed.apps.wayne.edu/#c-6-6)
- ![HELMET logo: a white football helmet with a gold facemask](https://creed.apps.wayne.edu/assets/marks/helmet.png)[HELMET](https://helmet.apps.wayne.edu/)Security headers and the runtime ribbon.[§2.2](https://creed.apps.wayne.edu/#c-2-2) [§4.3](https://creed.apps.wayne.edu/#c-4-3)
- ![Rollcall logo: an open register with gold ticks](https://creed.apps.wayne.edu/assets/marks/rollcall.png)[Rollcall](https://rollcall.apps.wayne.edu/) *new*Scans the apps and reports which PHP, Laravel and sign-in package each one runs.[§5.1](https://creed.apps.wayne.edu/#c-5-1)
- ![Pinstripe logo: a striped necktie with a gold tie bar](https://creed.apps.wayne.edu/assets/marks/pinstripe.png)[Pinstripe](https://pinstripe.apps.wayne.edu/)Code style presets for Pint, PHPStan, Rector, ESLint and Prettier.The handbook's code chapter
- ![SESAME logo: an open door with a gold gap](https://creed.apps.wayne.edu/assets/marks/sesame.png)[SESAME](https://sesame.apps.wayne.edu/)Sign-in through CAS, Entra ID or Drawbridge.The handbook's auth chapter
- ![Sourdough logo: a starter jar with a gold band](https://creed.apps.wayne.edu/assets/marks/sourdough.png)[Sourdough](https://sourdough.apps.wayne.edu/)Makes a new app with the stack already in it. Raw PHP only, for now.Starts on the floor in Table 2-1
- ![WELD logo: a welder's helmet](https://creed.apps.wayne.edu/assets/marks/weld.png)[WELD](https://weld.apps.wayne.edu/)A Laravel app with Inertia and Vue that has the whole stack installed.Adopts the whole code
- ![Booster logo: a syringe with rocket fins and a gold flame](https://creed.apps.wayne.edu/assets/marks/booster.png)[Booster](https://booster.apps.wayne.edu/) *new*Upgrades PHP and Laravel one version at a time.Gets you off a Grace expired row
- ![ADVISOR logo: a speech bubble with a gold compass needle](https://creed.apps.wayne.edu/assets/marks/advisor.png)[ADVISOR](https://advisor.apps.wayne.edu/) *new*An MCP server your agent can ask about the rules and dates.Explains; never gates (§6.4)
- ![Squire logo: a satchel with a gold buckle](https://creed.apps.wayne.edu/assets/marks/squire.png)[Squire](https://squire.apps.wayne.edu/) *new*Carries the release to Citadel. This page deploys with it.The deploy gate, proposed
- ![Drawbridge logo: a castle tower with its bridge down on a gold chain](https://creed.apps.wayne.edu/assets/marks/drawbridge.png)[Drawbridge](https://drawbridge.apps.wayne.edu/) *new*The Citadel sign-in gateway.Erratum E-7

Appendix A

## Adopting this code

Two ways in: read the policy from your own PHP tool, or clone CREED and run its checks.

### A.1 Read the policy from PHP

Any OS with PHP 8.3 or newer and Composer. On Packagist, 0.1.3

`$ composer require waynestate/creed`

```
use Waynestate\Creed\Policy;
use Waynestate\Creed\State;

$policy = Policy::bundled();

// What state is PHP 8.2 in, on this date?
$state = $policy->stateOf('php', '8.2', new DateTimeImmutable('2026-10-27'));
// State::Security

// What is the oldest PHP an app may declare?
$floor = $policy->oldestSupported('php', new DateTimeImmutable('2026-10-27'));
// '8.2'
```

### A.2 Run CREED's checks

In a clone with PHP and Composer, this runs every check: `composer validate`, PHPUnit, the policy schema, Pint, the handbook lint, the site staleness checks, PHPStan and `composer audit`. GitLab and GitHub run the same command.

`$ composer ci`

No PHP on your machine? Docker can run the tests and the schema check.

**Windows**

PowerShell, with Docker Desktop.

```
PS> docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
PS> docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
PS> docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate-policy
```

**macOS**

Terminal, with Docker Desktop.

```
$ docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
$ docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
$ docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate-policy
```

**Linux**

Any shell, with Docker Engine.

```
$ docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
$ docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
$ docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate-policy
```

Maintainers: to pull new patch numbers from the upstream feeds, run `php bin/refresh-patches.php --dry-run` (drop `--dry-run` to write).

**Strongly recommended:** a git.wayne.edu instance runner. CI is a gate only if something runs it (§6.7), and git.wayne.edu has no runners today.

Appendix B

## Hand it to your agent

Four prompts for whatever coding agent you use. Copy one, or open it in OpenCode, Claude Code, Codex or ChatGPT. Each tells the agent where CREED's pages are and what it must not assume, and all four tell it to ask you before it guesses.

### Get started with CREED

Lists every version your app runs with its state today, then proposes the smallest change that fixes anything past end of life. It won't make the change until you say so.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer bring the app in this folder up to CREED, the written standard of WELD, the Wayne State application stack. CREED is two things: policy.json, the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, and a handbook of numbered practices.

Read first:
- https://creed.apps.wayne.edu/llms.txt, the index of CREED's pages, each one as Markdown. Fetch the pages you need; do not work from memory.
- This app's composer.json and composer.lock, and package.json with its lock file if there is one.
- ADVISOR, the stack's MCP server, if it is connected: its policy_lookup and list_stack_rules tools answer from CREED, with the date and the source. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt

Task:
1. Say which kind of app this is: raw PHP, raw PHP with Vue, Laravel with Blade, Laravel with Blade and Vue, or Laravel with Inertia and Vue.
2. List every version it declares or locks: the PHP floor, Laravel, Node, Vue, Inertia, and MySQL if you can tell.
3. For each one, give the state CREED assigns it today (Active, Security, Eol or EolGraceExpired), the date that state ends, and the clause that applies.
4. Propose the smallest change that moves anything past end of life onto a supported line. Do not make it yet.

Constraints:
- CREED decides a version's state. Never guess a date: read it from the policy or from ADVISOR, and say which.
- An app that reads the policy itself installs the reader with `composer require waynestate/creed`. Never copy policy.json into the app.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess: a version you cannot read, an app kind you cannot tell, or anything that touches a deploy.

Output: a table (component, version, state, until, clause), then the change as numbered steps, then your questions.
```

### Or start from your consumer path

### CREED in a raw PHP app

Checks an app with no framework against the rules that apply to it, and cites a file and line for each result.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent reviewing a raw PHP app (no framework) at Wayne State University against CREED, the written standard of WELD, the Wayne State application stack.

Read first:
- https://creed.apps.wayne.edu/llms.txt, then the handbook chapters it lists. Every rule there cites the decision behind it.
- ADVISOR, the stack's MCP server, if it is connected: call list_stack_rules with permutation raw-php (raw-php-vue if the app builds Vue), and policy_lookup for each runtime. If it is not connected, say so and work from the handbook pages.
- composer.json, composer.lock, the entry scripts or front controller, and any CI file.

Task:
1. Check the app against the rules that apply to raw PHP: versions, dependencies, secrets and configuration, runtime and headers, auth, and CI.
2. For each rule, record Pass, Fail or Cannot tell, with the file and line that shows it.
3. Change nothing yet. Propose the three fixes that buy the most, smallest first.

Constraints:
- Cite the chapter and rule for every finding: the handbook file and line from ADVISOR, or the page URL.
- A raw PHP app has no framework to lean on. Say what the app itself must do, and never suggest adding Laravel just to meet a rule.
- The PHP floor comes from the policy, not from memory.
- Ask me before you guess, and before you touch anything that deploys.

Output: a findings table (rule, chapter, result, evidence), then the three fixes, then your questions.
```

### CREED in a Laravel app with Blade

The same review for Laravel with Blade. It looks at config, middleware and the Laravel line.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent reviewing a Laravel app that renders its pages with Blade, at Wayne State University, against CREED, the written standard of WELD, the Wayne State application stack.

Read first:
- https://creed.apps.wayne.edu/llms.txt, then the handbook chapters it lists. Every rule there cites the decision behind it.
- ADVISOR, the stack's MCP server, if it is connected: call list_stack_rules with permutation laravel-blade (laravel-blade-vue if Blade pages mount Vue components), and policy_lookup for PHP and Laravel. If it is not connected, say so and work from the handbook pages.
- composer.json, composer.lock, config/, bootstrap/app.php, .env.example, and any CI file.

Task:
1. Check the app against the rules that apply to Laravel with Blade: the PHP floor and the Laravel line, dependencies, secrets and .env, runtime and headers, auth, and CI.
2. For each rule, record Pass, Fail or Cannot tell, with the file and line that shows it.
3. Change nothing yet. Propose the three fixes that buy the most, smallest first.

Constraints:
- Cite the chapter and rule for every finding: the handbook file and line from ADVISOR, or the page URL.
- Meet a rule the Laravel way (config, middleware, a service provider) before writing code of your own.
- Laravel's support dates come from CREED's policy, not from memory or from laravel.com.
- Ask me before you guess, and before you touch migrations or anything that deploys.

Output: a findings table (rule, chapter, result, evidence), then the three fixes, then your questions.
```

### CREED in a Laravel app with Inertia and Vue

Reviews the Composer side and the npm side, including Node, Vue and Inertia. A missing npm lock file counts as a finding.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent reviewing a Laravel app with Inertia and Vue, at Wayne State University, against CREED, the written standard of WELD, the Wayne State application stack. It has two halves, and CREED covers both.

Read first:
- https://creed.apps.wayne.edu/llms.txt, then the handbook chapters it lists. Every rule there cites the decision behind it.
- ADVISOR, the stack's MCP server, if it is connected: call list_stack_rules with permutation laravel-inertia-vue, and policy_lookup for PHP, Laravel, Node, Vue and Inertia. If it is not connected, say so and work from the handbook pages.
- The PHP half: composer.json, composer.lock, config/, .env.example. The frontend half: package.json, its lock file, .npmrc, the Vite config. And any CI file.

Task:
1. Check the app against the rules that apply to Laravel with Inertia and Vue: the PHP, Laravel, Node, Vue and Inertia lines, dependencies on both sides, secrets and .env, runtime and headers, auth, and CI.
2. For each rule, record Pass, Fail or Cannot tell, with the file and line that shows it.
3. Change nothing yet. Propose the three fixes that buy the most, smallest first.

Constraints:
- Cite the chapter and rule for every finding: the handbook file and line from ADVISOR, or the page URL.
- The npm side counts as much as the Composer side: a missing or uncommitted lock file is a finding.
- Every support date comes from CREED's policy, never from memory.
- Ask me before you guess, and before you touch migrations, the build, or anything that deploys.

Output: a findings table (rule, chapter, result, evidence, half), then the three fixes, then your questions.
```

The agent reads the same pages you do: [llms.txt](https://creed.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://creed.apps.wayne.edu/llms-full.txt) holds them all in one file, and every page has a Markdown twin. Nothing here phones home. The buttons only copy text or open the tool you picked.
