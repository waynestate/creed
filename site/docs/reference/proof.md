---
title: "Proof | CREED docs"
description: "Where the proof runs against real Wayne State applications: proof.apps.wayne.edu/creed, behind a Wayne State employee sign-in."
url: https://creed.apps.wayne.edu/docs/reference/proof.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Proof[#](https://creed.apps.wayne.edu/docs/reference/proof.html#proof)

This page shows where CREED proves itself against real Wayne State applications and what the `composer ci` gate checks before anything ships.

## Where the proof runs[#](https://creed.apps.wayne.edu/docs/reference/proof.html#where-the-proof-runs)

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/creed](https://proof.apps.wayne.edu/creed/), behind a Wayne State employee sign-in.

Note

The front page, [`site/index.html`](https://creed.apps.wayne.edu/), is a static page you can open in a browser or serve from `site/`. On Citadel it is served by the raw-PHP deploy layer in `deploy/`: a Squire kit whose front controller serves `site/` behind HELMET's security headers. For local development, keep serving `site/` directly.

## What `composer ci` proves[#](https://creed.apps.wayne.edu/docs/reference/proof.html#what-composer-ci-proves)

`composer ci` is the same command that runs on GitLab and GitHub. Green means it passed on all three PHP versions on both. It runs the reader on PHP 8.3, 8.4 and 8.5, validates `policy/policy.json` against its schema, and runs the handbook lint. It also fails when the committed docs pages go stale, so the site you are reading cannot drift from `docs/`.

## The badges[#](https://creed.apps.wayne.edu/docs/reference/proof.html#the-badges)

The README carries four badges:

- `php ^8.3`: CREED itself requires PHP `^8.3`, like every stack package.
- `license MIT`.
- `policy schema v1, 6 components`: the schema is `version: 1`, and the policy lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia.
- GitHub CI: the status of the `composer ci` workflow on the mirror.

Creed Bratton keeps his records in a filing cabinet nobody can find; ours are in `docs/`, where anyone can.

## Related[#](https://creed.apps.wayne.edu/docs/reference/proof.html#related)

- [Install](https://creed.apps.wayne.edu/docs/install.html)
- [Troubleshooting](https://creed.apps.wayne.edu/docs/troubleshooting.html)
- [Upgrading](https://creed.apps.wayne.edu/docs/upgrading.html)
- [Changelog](https://creed.apps.wayne.edu/docs/changelog.html)
