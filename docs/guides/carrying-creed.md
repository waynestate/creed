---
title: Carrying CREED in another package
description: How PROCTOR, Booster and Rollcall carry a generated copy of the reader under their own namespace, and how bin/export.php keeps the copies in step.
---

# Carrying CREED in another package

This page explains who carries a copy of CREED's reader and policy, what the copy is, and how `bin/export.php` generates it and checks it.

## Who carries a copy

- PROCTOR, Booster and Rollcall each carry a generated copy of the reader and policy under their own namespace, made by `bin/export.php`, so two tools in one app never fight over a class name.
- ADVISOR carries CREED's policy and handbook as a data bundle instead, in its `data/`.
- HELMET can read a `policy.json` at runtime, and apps and the stack's front-page deploys install `waynestate/creed` from Packagist.

## Why the copies began

The copies began because CREED was not on Packagist. It is now (`waynestate/creed`, 0.1.11 at this writing), so each copy can become a real `require`, one consumer at a time; until a consumer switches, its copy stays.

## Generating and checking the copy

CREED owns the generator and the check:

```
php bin/export.php --namespace=Waynestate\Proctor\Creed --to=vendor/creed-copy
php bin/export.php --check --namespace=Waynestate\Proctor\Creed --to=vendor/creed-copy
```

The copy is the reader with the namespace rewritten and `bundled()` repointed at its own `policy.json`; `--check` fails when the copy drifts from the source. Creed Bratton would have a form for this.

## Related

- [The handbook](handbook.md)
- [Reading the policy from PHP](reading-the-policy.md)
- [Install](../install.md)
