---
title: Upgrading
description: How an app on an older PHP reaches the policy floor, and what the reader says on each boundary day.
---

# Upgrading

How CREED versions are cut, what each release changed that a consumer notices, and which consumers must refresh a generated copy after a policy change.

## How versions are cut

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, leaving an empty `## Unreleased`. (0.1.0 predates the heading convention, so it has none.)
2. `composer ci` green locally, then on both pipelines for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on git.wayne.edu. The mirror carries the tag to GitHub, and Packagist picks it up from there.
5. A release needs a maintainer's approval. Never move or delete a published tag.
6. Then bump `waynestate/creed` where the stack pins it, and refresh the generated copies.

## 0.1.7 (2026-11-07)

The policy records, per Laravel line, the PHP versions it supports and the minimum MySQL and MariaDB versions, in a new optional `requires` field on the line. A policy file that uses `requires` fails validation against the 0.1.6 schema, so a publisher updates the schema first. Readers ignore the field, so an older reader keeps working on a newer file. The same holds for the new optional `images` object on a line (`images.docker` is filled for PHP 8.3 to 8.5).

Nothing changes for a consumer in how the policy is kept: the policy robot's Monday merge requests land in `policy/policy.json` like any other commit, so a consumer that carries a copy refreshes it after a release as before.

## 0.1.6 (2026-11-03)

A `policy-bot` CI job runs `bin/policy-bot.php run` with `CREED_BOT_TOKEN` on a weekly schedule (the one carrying `CREED_JOB=policy-bot`) or by hand; without the token it skips green, and the check-only `refresh-patches` job now skips that schedule. Docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.

## 0.1.5 (2026-11-03)

The handbook grew: new chapters Secrets and Production from the WSU developer standards (2024), WSU developer standards (2024) rules in the Code, Auth, Databases, Dependencies and CI chapters, a note on MySQL lines and auth plugin, and BEST_PRACTICES section 11 (practices 55 to 61). Handbook rules now name the PROCTOR check that enforces them. The BEST_PRACTICES statements and gap list were fixed to match the tree, schema and tests. A test pins that an older reader accepts a newer policy file. CI pulls its Docker Hub images through the GitLab Dependency Proxy, a `wayneui-copies` gate job was added, and the unused `composer/semver` dependency was removed.

## 0.1.4 (2026-10-30)

The bot's GitLab HTTP client failed on every API call (`stream_context_create` options were not under the `http` wrapper, a ValueError on current PHP). It now works and reads response headers from the stream's metadata, so PHP 8.5 shows no deprecation. The deploy layer's Squire kit was refreshed to 0.3.3 and then 0.3.4 (the local `.env.squire` file is now git-ignored).

## 0.1.3 (2026-10-29)

The proof page moved to a private site that needs a Wayne State sign-in. Project names are written as words except acronyms. A real `bin/policy-bot.php run` now commits the data allowlist on `auto/policy-refresh` and force-pushes it with GitLab merge request push options (create, target, remove source branch, label `auto-data`, title; `merge_request.auto_merge` on exit 3 only); missing variables stop the run with exit 1 naming them, and every printed line masks the token as `[masked]`. After an exit 0, a refresh whose `refreshed_on` is 7 or more days old runs the refresher again with `--touch`. The robot keeps its GitLab alert issues in line with the report, one open issue per alert key, each labelled `auto-alert`. New `src/Bot/GitLabClient.php` is a small GitLab API client over an injectable `HttpTransport`, shipped with a dependency-free PHP streams transport; the token rides only in the `PRIVATE-TOKEN` header, never in a URL. Stale capability claims in the site and docs were fixed.

## 0.1.2 (2026-10-29)

`bin/refresh-policy.php`: a released version that loosens a constraint now exits 3 (changes written) with an alert in the report, instead of exit 4. Reader behavior: a version of a known component that is older than the oldest listed line (PHP 5.x to 8.0, Laravel 5 to 8, Inertia 0.x) is now `Eol`, with the reason `older than the oldest listed line (8.1)` from the new `belowOldestLineReason($component, $version)`, instead of being refused as an unknown version. Unlisted components and version strings that do not parse are still refused, and a version above every listed line is still `Active`.

## 0.1.1 (2026-10-29)

`bin/refresh-policy.php` keeps every support date, patch and new line tied to a canonical source (php.net, the Node.js release schedule, endoflife.date, Packagist, npm). Changes are classed tighten, neutral, loosen-warning or loosen-fail; a later security date is held until two sources agree or 14 days pass; nothing is ever removed. Provenance lives in the new `policy/sources.json` (schema and validation included), and the policy gains an optional `refreshed_on` date. Policy lines may be marked `"current": true`: Active on every date, with no `security_until`; the schema rejects a current line that also carries a `security_until`, and a line that carries neither. `stateOf()` resolves a version to its policy line before judging it: a version that sorts above every listed line is `Active` (current versions are never refused), while a version between listed lines still throws. New reader method `isAheadOfPolicy($component, $version)`; `line()` reports a fifth field, `current` (true or null). The bundled policy lists Vue 3 and Inertia 2 as current lines. `bin/refresh-patches.php` reads the endoflife.date Vue feed and proposes new Vue lines alongside the patch refresh. The README's reader example is now a test: `tests/ReadmeTest.php` runs every PHP code block that uses the reader against the bundled policy and asserts the values printed beside it. The handbook is now linted for RFC 2119 capitalization, every chapter declares RFC 2119 and uses capital keywords, and `handbook/databases.md` gains a "Grace period" section spelling out the 90-day `eol_grace_days` window (`Security` on `security_until`, `Eol` for the window, `EolGraceExpired` after, no override). `handbook/dependencies.md` gains a "Private packages" section. PHPStan now includes Pinstripe's raw preset instead of carrying a hand copy.

## 0.1.0 (2026-10-27)

First tagged release.

## Which consumers must refresh a generated copy

PROCTOR, Rollcall and Booster each carry a generated copy of the reader under their own namespace, so two tools in one app never fight over a class name; ADVISOR carries CREED's policy and handbook as a data bundle instead. After any policy change, the copies downstream are stale until refreshed. In each consumer (PROCTOR, Rollcall, Booster), run `php <creed>/bin/export.php --namespace='Waynestate\<Project>\Creed' --to=src/Creed`, then `composer creed-copy:check` with `CREED_PATH` pointing at this checkout. ADVISOR regenerates with `content:generate`.

Creed Bratton would have a form for this.

## Related

- [Changelog](changelog.md)
- [Install](install.md)
- [Troubleshooting](troubleshooting.md)
- [Proof](reference/proof.md)
