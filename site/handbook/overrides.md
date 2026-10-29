---
title: "CREED Handbook: 11 Overrides"
description: "Chapter 11 of the CREED handbook, the written rules for the Wayne State app stack: Overrides."
url: https://creed.apps.wayne.edu/handbook/overrides.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 11

# Overrides

5 provisions

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

- Overrides are named, dated and visible (the stack's version policy).
- An app keeps a `policy-overrides.json` with an expiry date and a reason (the stack's version policy).
- Check prints every override on every run, and Fleet lists them (the stack's version policy).
- An expired override is a failure (first-repos).
- On the Citadel side, overrides are held by Citadel admins, per app and per rule, with an expiry, a reason and an approver. There is no permanent override (the stack's deploy-gate decision).

Set from [`handbook/overrides.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/overrides.md), impression `d54de99`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
