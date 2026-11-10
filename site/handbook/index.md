---
title: "CREED Handbook: the rules, chapter by chapter"
description: "The CREED handbook: the written rules for the Wayne State app stack, chapter by chapter, each citing the decision behind it."
url: https://creed.apps.wayne.edu/handbook/
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

The handbook · 2026 Edition

# The CREED handbook

The rules for the Wayne State app stack, chapter by chapter. Each chapter states what the ADRs have decided or what WSU's developer standards (2024) already require, and names its undecided points under a "Not decided here" heading.

Thirteen chapters · 183 provisions · **MUST** 80 · **SHOULD** 21 · **MAY** 1

## Contents

1. [1 Versions Latest patches · Base images · Node · Tool minimums](https://creed.apps.wayne.edu/handbook/versions.html)4 sections · 4 provisions · **MUST NOT** 2 · **MUST** 3Decided
2. [2 Dependencies Private packages](https://creed.apps.wayne.edu/handbook/dependencies.html)1 section · 15 provisions · **MUST NOT** 5 · **MUST** 7 · **SHOULD NOT** 1 · **SHOULD** 3Decided
3. [3 Code PHP style · Reading the code · Source control and documentation · Static analysis · Input and files · Banned patterns · Vue and JavaScript · Security analysis · How it is enforced · Which preset enforces each rule · Not adopted · To be decided · Sources](https://creed.apps.wayne.edu/handbook/code.html)13 sections · 43 provisions · **MUST NOT** 3 · **MUST** 9 · **SHOULD** 5Partly decided
4. [4 Auth](https://creed.apps.wayne.edu/handbook/auth.html)14 provisions · **MUST** 8 · **SHOULD** 1Decided
5. [5 Databases What the stack supports · Which MySQL lines the policy lists · Grace period · Names, comments and connections · Conventions that keep 5.6 working · Avoid until the server is upgraded · Moving to 8.4 · How the stack checks it · Sources](https://creed.apps.wayne.edu/handbook/databases.html)9 sections · 27 provisions · **MUST NOT** 1 · **MUST** 5 · **SHOULD** 2 · **MAY** 1Decided
6. [6 Runtime and headers Enforcement · Headers · Proxy trust · Sessions · Health · Metrics · Errors in production · Not decided here](https://creed.apps.wayne.edu/handbook/runtime-and-headers.html)8 sections · 16 provisions · **MUST NOT** 1 · **MUST** 2 · **SHOULD NOT** 1Decided
7. [7 Deploy gate On Citadel · Elsewhere](https://creed.apps.wayne.edu/handbook/deploy-gate.html)2 sections · 8 provisionsDecided
8. [8 CI](https://creed.apps.wayne.edu/handbook/ci.html)8 provisions · **MUST NOT** 1 · **MUST** 2 · **SHOULD** 1Decided
9. [9 Accessibility Target · Components · Checks · Not decided here](https://creed.apps.wayne.edu/handbook/accessibility.html)4 sections · 9 provisions · **MUST NOT** 1 · **MUST** 1 · **SHOULD** 2Decided
10. [10 Every OS](https://creed.apps.wayne.edu/handbook/every-os.html)6 provisions · **MUST NOT** 1 · **MUST** 5Decided
11. [11 Secrets and debugging Credentials · Errors and debug output · Not decided here](https://creed.apps.wayne.edu/handbook/secrets.html)3 sections · 9 provisions · **MUST NOT** 4 · **MUST** 5 · **SHOULD NOT** 1Decided
12. [12 Production Environments · Running safely · Who can reach it](https://creed.apps.wayne.edu/handbook/production.html)3 sections · 17 provisions · **MUST NOT** 3 · **MUST** 8 · **SHOULD** 4Decided
13. [13 Overrides](https://creed.apps.wayne.edu/handbook/overrides.html)7 provisions · **MUST NOT** 1 · **MUST** 2Decided

## How to read this book

- **[§3.2](https://creed.apps.wayne.edu/handbook/#contents)**: Chapter 3, section 2. Every section number is a link to itself: copy it to cite the rule.
- ****MUST** **SHOULD** **MAY****: The RFC 2119 key words, set in small capitals so a requirement never hides in a sentence.
- **(the stack's version policy)**: The decision a rule comes from. Rules state what is decided and cite it.
- **Not yet**: "To be decided": the gap is printed, and no rule is stated in its place.

[The whole book on one page](https://creed.apps.wayne.edu/handbook/print.html)

For printing, or for your browser's find. Every chapter, in order, from the same Markdown.

Set from [`handbook/README.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/README.md), impression `249720c`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
