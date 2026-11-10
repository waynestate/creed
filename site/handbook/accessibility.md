---
title: "CREED Handbook: 9 Accessibility"
description: "Chapter 9 of the CREED handbook, the written rules for the Wayne State app stack: Accessibility."
url: https://creed.apps.wayne.edu/handbook/accessibility.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 9

# Accessibility

4 sections · 9 provisions · **MUST NOT** 1 · **MUST** 1 · **SHOULD** 2

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

## [§9.1](https://creed.apps.wayne.edu/handbook/accessibility.html#target) Target

- Every public page of a stack app targets WCAG 2.2 Level AA (the stack's accessibility design).
- W3C says content conforming to 2.2 also conforms to 2.1 and 2.0, so the target meets the WCAG 2.1 AA floor named in the ADA Title II web rule (the stack's accessibility design).

## [§9.2](https://creed.apps.wayne.edu/handbook/accessibility.html#components) Components

- [WayneUI](https://wayneui.apps.wayne.edu/) is the stack's component library; pages built from its components start from markup and tokens already checked by WayneUI's own runnable accessibility gate (the stack's accessibility design).
- An app **MUST NOT** fork or restyle a WayneUI component in a way that removes focus indicators, labels or contrast (the stack's accessibility design). [PROCTOR](https://proctor.apps.wayne.edu/) check: `wayneui-forked-views` catches forked views.

## [§9.3](https://creed.apps.wayne.edu/handbook/accessibility.html#checks) Checks

- An automated accessibility check **SHOULD** run in CI on the app's key pages (the stack's accessibility design). Held by: [Preflight](https://preflight.apps.wayne.edu/)'s `a11y` component, below.
- A manual keyboard and screen reader pass **MUST** happen before launch. An automated tool catches regressions; it is not proof of conformance, and it never replaces the pass (the stack's accessibility design). No check exists yet.
- Preflight's `a11y` component (0.5.0) is the stack's CI check: it opens the app's pages in Chromium, runs axe-core in light and dark at 320 and 1280 px, and fails the pipeline on any violation. An app **SHOULD** add `wcag22aa` to its tags for the WCAG 2.2 AA target above. Held by: Preflight's a11y component (templates/a11y/template.yml).

## [§9.4](https://creed.apps.wayne.edu/handbook/accessibility.html#not-decided-here) Not decided here

- Whether and when the ADA Title II web rule applies to a given WSU unit. The rule applies WCAG 2.1 AA to public entities on dates that depend on the entity's size; the answer is for WSU's counsel and accessibility office, and this chapter does not decide it.
- Which pages need a manual audit.

Set from [`handbook/accessibility.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/accessibility.md), impression `5ec0e84`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
