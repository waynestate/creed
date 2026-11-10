---
title: "CREED Handbook: 8 CI"
description: "Chapter 8 of the CREED handbook, the written rules for the Wayne State app stack: CI."
url: https://creed.apps.wayne.edu/handbook/ci.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 8

# CI

8 provisions · **MUST NOT** 1 · **MUST** 6 · **SHOULD** 1

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

- CI is enforcement point (c): a red pipeline before merge (the stack's enforcement design). [PROCTOR](https://proctor.apps.wayne.edu/) check: `ci-pipelines`.
- The CI components are [Preflight](https://preflight.apps.wayne.edu/)'s: `proctor`, `php-test` and `composer-audit`, included with `include: component:` (first-repos).
- CI judges with deterministic commands, never with a model. A gate **MUST NOT** depend on a model's opinion (the stack's rule on language models). No check exists yet.
- Components **MUST** be pinned at a tag or SHA, never `~latest` (first-repos). PROCTOR check: `preflight-pinned`.
- The components run on the git.wayne.edu instance runner (first-repos), and every product strongly recommends the instance runner.
- Every change **MUST** land through a merge request that another person reviews, and critical findings **MUST** be addressed before merge (**SHOULD** for a one-person project: record the self-review in the merge request) (WSU developer standards, 2024). No check exists yet.
- Every project **MUST** have a defined, well-understood change-control process with change requests; review and approval of merge requests is change control only for simple projects, and the process **MUST** be followed strictly (WSU developer standards, 2024). No check exists yet.
- Known problem: the change-request procedure on Citadel is currently broken and needs fixing there; until it is fixed, changes **MUST** be recorded through reviewed merge requests and the Citadel team told. No check exists yet.

Set from [`handbook/ci.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/ci.md), impression `f64b9a6`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
