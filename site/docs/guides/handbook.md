---
title: "The handbook | CREED docs"
description: "The rules, chapter by chapter, in handbook/, with each rule linking the decision behind it."
url: https://creed.apps.wayne.edu/docs/guides/handbook.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The handbook[#](https://creed.apps.wayne.edu/docs/guides/handbook.html#the-handbook)

This page maps the CREED handbook: what it is, how to read its key words and PROCTOR check lines, and where each of the thirteen chapters lives.

## What the handbook is[#](https://creed.apps.wayne.edu/docs/guides/handbook.html#what-the-handbook-is)

The rules for the Wayne State app stack, chapter by chapter. Each chapter states what the ADRs have decided or what WSU's developer standards (2024) already require, and names its undecided points under a "Not decided here" heading. The rules, chapter by chapter, live in the handbook site, built separately from `handbook/`.

## How to read a rule[#](https://creed.apps.wayne.edu/docs/guides/handbook.html#how-to-read-a-rule)

- The key words MUST, MUST NOT, SHOULD, SHOULD NOT and MAY in each chapter are to be interpreted as described in RFC 2119.
- Every rule names what holds it, with one of three tags: "PROCTOR check: `<id>`" (the PROCTOR check that reports a breach), "Held by: ..." (any other point that holds it, such as a Preflight component or a runtime package's config) or "No check exists yet".
- `composer lint-handbook` fails when a rule that uses MUST, SHOULD or MAY carries none of the three, so a rule cannot quietly lose its tag. Like any good frontman, every rule has to say who is backing it.

## The chapters[#](https://creed.apps.wayne.edu/docs/guides/handbook.html#the-chapters)

- [Versions](https://creed.apps.wayne.edu/handbook/versions.html): the stack's version policy, the state table from active to EOL plus 90 days, with the dates in `policy/policy.json` and every tool deriving a version's state from the date.
- [Dependencies](https://creed.apps.wayne.edu/handbook/dependencies.html): no unbounded constraints, a committed lock file, an audit with no advisories at or above the policy's severity threshold, and the private-packages registry rules.
- [Code](https://creed.apps.wayne.edu/handbook/code.html): PHP style with one Pint preset per repo, reading the code, static analysis at PHPStan level 8, banned patterns, and Vue and JavaScript linting.
- [Auth](https://creed.apps.wayne.edu/handbook/auth.html): one protocol-neutral library that returns a verified identity and leaves the session to the app, with its drivers and non-EOL PHP only.
- [Databases](https://creed.apps.wayne.edu/handbook/databases.html): the MySQL lines the policy lists, the conventions that keep an app working on the shared 5.6 server, and the 90-day grace period.
- [Runtime and headers](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html): the runtime (Baseline) warns and refuses only for unsafe config, HELMET's headers on every response, proxy trust from a platform preset or the app's own list, and debug off in production.
- [Deploy gate](https://creed.apps.wayne.edu/handbook/deploy-gate.html): the gate runs PROCTOR against the commit being released, before it is released, and refuses EOL PHP.
- [CI](https://creed.apps.wayne.edu/handbook/ci.html): CI is enforcement point (c), a red pipeline before merge, built from Preflight's pinned components and judged with deterministic commands, never with a model.
- [Accessibility](https://creed.apps.wayne.edu/handbook/accessibility.html): WCAG 2.2 Level AA as the target for every public page, WayneUI components as the checked starting point, Preflight's automated axe-core check in CI, and the open Title II question.
- [Every OS](https://creed.apps.wayne.edu/handbook/every-os.html): every developer tool works on Windows, macOS and Linux, with `.ps1` and `.sh` pairs, LF line endings and portable paths.
- [Secrets](https://creed.apps.wayne.edu/handbook/secrets.html): credentials never in version control and never in the served folder, with errors and debug output that hide them.
- [Production](https://creed.apps.wayne.edu/handbook/production.html): pre-production and production environments, running safely with the least access needed, and who can reach an app.
- [Overrides](https://creed.apps.wayne.edu/handbook/overrides.html): overrides are named, dated and visible, with an expiry date and a reason, and an expired override is a failure.

## Related[#](https://creed.apps.wayne.edu/docs/guides/handbook.html#related)

- [Carrying CREED in another package](https://creed.apps.wayne.edu/docs/guides/carrying-creed.html)
- [Support states and the grace period](https://creed.apps.wayne.edu/docs/guides/support-states.html)
- [The instance runner](https://creed.apps.wayne.edu/docs/guides/instance-runner.html)
