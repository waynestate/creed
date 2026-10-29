---
title: "CREED Handbook: the rules, chapter by chapter"
description: "The CREED handbook: the written rules for the Wayne State app stack, chapter by chapter, each citing the decision behind it."
url: https://creed.apps.wayne.edu/handbook/
llms: https://creed.apps.wayne.edu/llms.txt
generator: SQUIRE agent-ready
---

The handbook · 2026 Edition

# The CREED handbook

The rules for the Wayne State app stack, chapter by chapter. Each chapter states what the ADRs have already decided, with the ADR number, and marks the rest as "To be decided".

Eleven chapters · 105 provisions · **MUST** 14 · **SHOULD** 2 · **MAY** 1

## Contents

1. [1 Versions Latest patches · Tool minimums](https://creed.apps.wayne.edu/handbook/versions.html)2 sections · 4 provisions · **MUST** 1Decided
2. [2 Dependencies Private packages](https://creed.apps.wayne.edu/handbook/dependencies.html)1 section · 13 provisions · **MUST NOT** 4 · **MUST** 6 · **SHOULD NOT** 1 · **SHOULD** 1Decided
3. [3 Code PHP style · Static analysis · Banned patterns · Vue and JavaScript · Security analysis · How it is enforced · Which preset enforces each rule · Not adopted · To be decided · Sources](https://creed.apps.wayne.edu/handbook/code.html)10 sections · 33 provisions · **MUST NOT** 1Partly decided
4. [4 Auth](https://creed.apps.wayne.edu/handbook/auth.html)6 provisionsPartly decided
5. [5 Databases What the stack supports · Grace period · Conventions that keep 5.6 working · Avoid until the server is upgraded · Moving to 8.4 · How the stack checks it · Sources](https://creed.apps.wayne.edu/handbook/databases.html)7 sections · 23 provisions · **MAY** 1Decided
6. [6 Runtime and headers](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html)2 provisionsTo be decided
7. [7 Deploy gate On Citadel · Elsewhere](https://creed.apps.wayne.edu/handbook/deploy-gate.html)2 sections · 8 provisionsDecided
8. [8 CI](https://creed.apps.wayne.edu/handbook/ci.html)5 provisions · **MUST** 1Decided
9. [9 Accessibility](https://creed.apps.wayne.edu/handbook/accessibility.html)1 provisionTo be decided
10. [10 Every OS](https://creed.apps.wayne.edu/handbook/every-os.html)5 provisions · **MUST** 1Decided
11. [11 Overrides](https://creed.apps.wayne.edu/handbook/overrides.html)5 provisionsDecided

## How to read this book

- **[§3.2](https://creed.apps.wayne.edu/handbook/#contents)**: Chapter 3, section 2. Every section number is a link to itself: copy it to cite the rule.
- ****MUST** **SHOULD** **MAY****: The RFC 2119 key words, set in small capitals so a requirement never hides in a sentence.
- **(the stack's version policy)**: The decision a rule comes from. Rules state what is decided and cite it.
- **Not yet**: "To be decided": the gap is printed, and no rule is stated in its place.

[The whole book on one page](https://creed.apps.wayne.edu/handbook/print.html)

For printing, or for your browser's find. Every chapter, in order, from the same Markdown.

Set from [`handbook/README.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/README.md), impression `c9c229b`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
