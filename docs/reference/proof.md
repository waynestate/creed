---
title: Proof
description: Where the proof runs against real Wayne State applications: proof.apps.wayne.edu/creed, behind a Wayne State employee sign-in.
---

# Proof

This page shows where CREED proves itself against real Wayne State applications and what the `composer ci` gate checks before anything ships.

## Where the proof runs

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/creed](https://proof.apps.wayne.edu/creed/), behind a Wayne State employee sign-in.

> [!NOTE]
> The front page, [`site/index.html`](https://creed.apps.wayne.edu/), is a static page you can open in a browser or serve from `site/`. On Citadel it is served by the raw-PHP deploy layer in `deploy/`: a Squire kit whose front controller serves `site/` behind HELMET's security headers. For local development, keep serving `site/` directly.

## What `composer ci` proves

`composer ci` is the same command on GitLab and GitHub. It validates `composer.json`, runs the test suite (274 tests in 22 files on 2026-11-06), validates `policy/policy.json` and `policy/sources.json` against their schemas, checks style with Pint, lints the handbook, runs PHPStan and `composer audit`, and fails when the handbook site, the agent-ready files or the docs pages go stale, so the site you are reading cannot drift from `docs/`.

Where it runs: GitHub runs `composer ci` on PHP 8.3, 8.4 and 8.5. GitLab runs the test suite on all three through Preflight's `php-test` component, and `composer ci` once on the pinned Composer image (PHP 8.5). GitLab also runs PROCTOR against the front page's `deploy/` layer (allowed to fail while its known findings stand) and the strict WayneUI copies check.

The policy itself is proved every Monday: the [policy robot](../guides/policy-bot.md) re-reads the upstream sources and opens a merge request when anything moved.

## The badges

The README carries four badges:

- `php ^8.3`: CREED itself requires PHP `^8.3`, like every stack package.
- `license MIT`.
- `policy schema v1, 6 components`: the schema is `version: 1`, and the policy lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia.
- GitHub CI: the status of the `composer ci` workflow on the mirror.

Creed Bratton keeps his records in a filing cabinet nobody can find; ours are in `docs/`, where anyone can.

## Related

- [Install](../install.md)
- [Troubleshooting](../troubleshooting.md)
- [Upgrading](../upgrading.md)
- [Changelog](../changelog.md)
