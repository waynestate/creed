---
title: "CREED Handbook: 7 Deploy gate"
description: "Chapter 7 of the CREED handbook, the written rules for the Wayne State app stack: Deploy gate."
url: https://creed.apps.wayne.edu/handbook/deploy-gate.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: SQUIRE agent-ready
---

Chapter 7

# Deploy gate

2 sections · 8 provisions

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

The deploy gate runs [PROCTOR](https://proctor.apps.wayne.edu/) against the commit being released, before it is released, and refuses EOL PHP (the stack's deploy-gate decision).

- The deploy-time rule set is small, offline and fast: the target image's status and its PHP version against [`policy.json`](https://github.com/waynestate/creed/blob/main/policy/policy.json), the framework and stack package versions in `composer.lock`, and known advisories from a cached database (the stack's deploy-gate decision).
- EOL fails: a deploy that regresses a rule, a new app that fails any rule, or an expired override is blocked (the stack's deploy-gate decision).
- It ratchets: a deploy that doesn't make things worse always passes (the stack's deploy-gate decision).
- Each rule moves through stages, `report`, `warn`, `block`, with dates in `policy.json` (the stack's deploy-gate decision).

## [§7.1](https://creed.apps.wayne.edu/handbook/deploy-gate.html#on-citadel) On Citadel

- Citadel runs the gate in its release hook: `proctor gate` in `runGitPull()`, against the fetched commit, before it is checked out (the stack's deploy-gate decision).
- The gate is pending the Citadel team, which is still an open question. Until it is adopted, enforcement is CI on the test GitLab instance (the stack's deploy-gate decision).

## [§7.2](https://creed.apps.wayne.edu/handbook/deploy-gate.html#elsewhere) Elsewhere

- The same gate runs as a CI job through [PREFLIGHT](https://preflight.apps.wayne.edu/)'s `proctor` component, wherever the pipeline runs (first-repos).
- [SQUIRE](https://squire.apps.wayne.edu/)'s `docker` target will run the gate before it ships an image, and so will its later `openshift` and `coolify` targets (the stack's rule that packages run on any container platform).

Set from [`handbook/deploy-gate.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/deploy-gate.md), impression `7023c88`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
