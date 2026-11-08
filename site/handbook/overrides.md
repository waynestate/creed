---
title: "CREED Handbook: 13 Overrides"
description: "Chapter 13 of the CREED handbook, the written rules for the Wayne State app stack: Overrides."
url: https://creed.apps.wayne.edu/handbook/overrides.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 13

# Overrides

6 provisions

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

- Overrides are named, dated and visible (the stack's version policy).
- An app keeps a `policy-overrides.json` with an expiry date and a reason (the stack's version policy). [PROCTOR](https://proctor.apps.wayne.edu/) check: `overrides`.
- Check prints every override on every run, and Fleet lists them (the stack's version policy).
- An expired override is a failure (first-repos). PROCTOR check: `overrides`.
- An override runs at most `rules.override_max_days` days past today (90 in the policy). A longer one is a failure and does not apply. PROCTOR check: `overrides`.
- On the Citadel side, overrides are held by Citadel admins, per app and per rule, with an expiry, a reason and an approver. There is no permanent override (the stack's deploy-gate decision).

Set from [`handbook/overrides.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/overrides.md), impression `2c42365`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
