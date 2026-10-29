---
title: "CREED Handbook: 8 CI"
description: "Chapter 8 of the CREED handbook, the written rules for the Wayne State app stack: CI."
url: https://creed.apps.wayne.edu/handbook/ci.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: SQUIRE agent-ready
---

Chapter 8

# CI

5 provisions · **MUST** 1

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

- CI is enforcement point (c): a red pipeline before merge (the stack's enforcement design).
- The CI components are [PREFLIGHT](https://preflight.apps.wayne.edu/)'s: `proctor`, `php-test` and `composer-audit`, included with `include: component:` (first-repos).
- CI judges with deterministic commands, never with a model. No gate depends on a model's opinion (the stack's rule on language models).
- Components are pinned at a tag or SHA, never `~latest` (first-repos).
- git.wayne.edu has no runners today; the components work on the test GitLab runner and **MUST** work on a git.wayne.edu runner when one exists (first-repos). Every product strongly recommends the instance runner.

Set from [`handbook/ci.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/ci.md), impression `8543c96`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
