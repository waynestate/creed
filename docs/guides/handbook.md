---
title: The handbook
description: The rules, chapter by chapter, in handbook/, with each rule linking the decision behind it.
---

# The handbook

This page maps the CREED handbook: what it is, how to read its key words and PROCTOR check lines, and where each of the fourteen chapters lives.

## What the handbook is

The rules for the Wayne State app stack, chapter by chapter. Each chapter states what the ADRs have decided or what WSU's developer standards (2024) already require, and names its undecided points under a "Not decided here" heading. The rules, chapter by chapter, live in the handbook site, built separately from `handbook/`.

## How to read a rule

- The key words MUST, MUST NOT, SHOULD, SHOULD NOT and MAY in each chapter are to be interpreted as described in RFC 2119.
- Every rule names what holds it, with one of three tags: "PROCTOR check: `<id>`" (the PROCTOR check that reports a breach), "Held by: ..." (any other point that holds it, such as a Preflight component or a runtime package's config) or "No check exists yet".
- `composer lint-handbook` fails when a rule that uses MUST, SHOULD or MAY carries none of the three, so a rule cannot quietly lose its tag. Like any good frontman, every rule has to say who is backing it.

## The chapters

- [Versions](../../handbook/versions.html): the stack's version policy, the state table from active to EOL plus 90 days, with the dates in `policy/policy.json` and every tool deriving a version's state from the date.
- [Dependencies](../../handbook/dependencies.html): no unbounded constraints, a committed lock file, an audit with no advisories at or above the policy's severity threshold, and the private-packages registry rules.
- [Code](../../handbook/code.html): PHP style with one Pint preset per repo, reading the code, static analysis at PHPStan level 8, banned patterns, and Vue and JavaScript linting.
- [Auth](../../handbook/auth.html): one protocol-neutral library that returns a verified identity and leaves the session to the app, with its drivers and non-EOL PHP only.
- [Databases](../../handbook/databases.html): the MySQL lines the policy lists, the conventions that keep an app working on the shared 5.6 server, and the 90-day grace period.
- [Runtime and headers](../../handbook/runtime-and-headers.html): the runtime (Baseline) warns and refuses only for unsafe config, HELMET's headers on every response, proxy trust from a platform preset or the app's own list, and debug off in production.
- [Deploy gate](../../handbook/deploy-gate.html): the gate runs PROCTOR against the commit being released, before it is released, and refuses EOL PHP.
- [CI](../../handbook/ci.html): CI is enforcement point (c), a red pipeline before merge, built from Preflight's pinned components and judged with deterministic commands, never with a model.
- [Accessibility](../../handbook/accessibility.html): WCAG 2.2 Level AA as the target for every public page, WayneUI components as the checked starting point, Preflight's automated axe-core check in CI, and the open Title II question.
- [Every OS](../../handbook/every-os.html): every developer tool works on Windows, macOS and Linux, with `.ps1` and `.sh` pairs, LF line endings and portable paths.
- [Secrets](../../handbook/secrets.html): credentials never in version control and never in the served folder, with errors and debug output that hide them.
- [AI and LLM use](../../handbook/ai.html): model calls server-side only, tokens out of client code, outside providers receiving public data only, personal data out of prompts, and a person, not a model, making the final call.
- [Production](../../handbook/production.html): pre-production and production environments, running safely with the least access needed, and who can reach an app.
- [Overrides](../../handbook/overrides.html): overrides are named, dated and visible, with an expiry date and a reason, and an expired override is a failure.

## Related

- [Carrying CREED in another package](carrying-creed.md)
- [Support states and the grace period](support-states.md)
- [The instance runner](instance-runner.md)
