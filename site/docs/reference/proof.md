---
title: "Proof | CREED docs"
description: "Where the proof runs against real Wayne State applications: proof.apps.wayne.edu/creed, behind a Wayne State employee sign-in."
url: https://creed.apps.wayne.edu/docs/reference/proof.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof [#](https://creed.apps.wayne.edu/docs/reference/proof.html#proof)

This page shows where CREED proves itself against real Wayne State applications and what the `composer ci` gate checks before anything ships.

## Where the proof runs [#](https://creed.apps.wayne.edu/docs/reference/proof.html#where-the-proof-runs)

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/creed](https://proof.apps.wayne.edu/creed/), behind a Wayne State employee sign-in.

Public pages never count or name the applications. When CREED's public copy speaks about the proof, it uses percentages and trends, such as "about nine in ten scanned apps", and nothing that points at an app or a team. `tests/PublicProofCopyTest.php` fails `composer ci` when a public page states an app count.

Note

The front page, [`site/index.html`](https://creed.apps.wayne.edu/), is a static page you can open in a browser or serve from `site/`. On Citadel it is served by the raw-PHP deploy layer in `deploy/`: a Squire kit whose front controller serves `site/` behind HELMET's security headers. For local development, keep serving `site/` directly.

## What `composer ci` proves [#](https://creed.apps.wayne.edu/docs/reference/proof.html#what-composer-ci-proves)

`composer ci` is the same command on GitLab and GitHub. It validates `composer.json`, runs the test suite (294 tests in 25 files on 2026-11-08), validates `policy/policy.json` and `policy/sources.json` against their schemas, checks style with Pint, lints the handbook, runs PHPStan and `composer audit`, and fails when the handbook site, the agent-ready files or the docs pages go stale, so the site you are reading cannot drift from `docs/`.

Where it runs: GitHub runs `composer ci` on PHP 8.3, 8.4 and 8.5. GitLab runs the test suite on all three through Preflight's `php-test` component, and `composer ci` once on the pinned Composer image (PHP 8.5). GitLab also runs PROCTOR against the front page's `deploy/` layer (allowed to fail while its known findings stand) and the strict WayneUI copies check.

The policy itself is proved every Monday: the [policy robot](https://creed.apps.wayne.edu/docs/guides/policy-bot.html) re-reads the upstream sources and, when anything moved, pushes the refresh to `main` if `composer ci` is green, or opens a merge request for a person if it is red.

## The badges [#](https://creed.apps.wayne.edu/docs/reference/proof.html#the-badges)

The README carries four badges:

- `php ^8.3`: CREED itself requires PHP `^8.3`, like every stack package.
- `license MIT`.
- `policy schema v1, 6 components`: the schema is `version: 1`, and the policy lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia.
- GitHub CI: the status of the `composer ci` workflow on the mirror.

Creed Bratton keeps his records in a filing cabinet nobody can find; ours are in `docs/`, where anyone can.

## Related [#](https://creed.apps.wayne.edu/docs/reference/proof.html#related)

- [Install](https://creed.apps.wayne.edu/docs/install.html)
- [Troubleshooting](https://creed.apps.wayne.edu/docs/troubleshooting.html)
- [Upgrading](https://creed.apps.wayne.edu/docs/upgrading.html)
- [Changelog](https://creed.apps.wayne.edu/docs/changelog.html)
