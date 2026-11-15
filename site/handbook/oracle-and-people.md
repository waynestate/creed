---
title: "CREED Handbook: 6 Oracle, Banner and people"
description: "Chapter 6 of the CREED handbook, the written rules for the Wayne State app stack: Oracle, Banner and people."
url: https://creed.apps.wayne.edu/handbook/oracle-and-people.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 6

# Oracle, Banner and people

5 sections · 15 provisions · **MUST NOT** 6 · **MUST** 6 · **SHOULD** 2

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

## [§6.1](https://creed.apps.wayne.edu/handbook/oracle-and-people.html#reading-oracle) Reading Oracle

- App code **MUST** reach Oracle through TELLER's named queries (reviewed SQL in a catalog file), never by calling PHP's OCI functions (`oci_connect`, `oci_parse` and the rest) or `new PDO('oci:...')` itself. No check exists yet; [PROCTOR](https://proctor.apps.wayne.edu/)'s `raw-oci` warning is in review.
- Every value in an Oracle query **MUST** be a bound variable, and SQL **MUST NOT** be built by interpolation or concatenation. No check exists yet.
- Reads **SHOULD** run in a read-only transaction with a row cap. Held by: TELLER, for every query that goes through it (read-only transaction, 1,000-row default cap).
- An app **MUST NOT** write to Banner tables through a read account; a write need goes to the data's owners first. No check exists yet.

## [§6.2](https://creed.apps.wayne.edu/handbook/oracle-and-people.html#credentials-and-the-client) Credentials and the client

- Oracle passwords **MUST NOT** appear in a DSN, a committed `tnsnames.ora` or a committed wallet; they come from the environment at deploy time. No check exists yet.
- Oracle Instant Client **MUST NOT** be committed to the repo; the runtime image provides it. PROCTOR check: `instant-client-committed`.

## [§6.3](https://creed.apps.wayne.edu/handbook/oracle-and-people.html#people) People

- Person lookups **SHOULD** go through Rolodex, which applies the confidentiality flag, the app's granted profiles, the 25-per-page cap and the access log in one place. Held by: Rolodex, for apps that use it.
- A person flagged confidential in Banner **MUST NOT** appear in a directory answer beyond what the app's grant allows. Held by: Rolodex, for apps that use it; nothing checks hand-written lookups.
- Searches for people **MUST** travel in POST bodies, never in query strings, because URLs end up in server and proxy logs. Held by: Rolodex's search endpoint and its `useRolodexSearch()` Vue composable, for apps that use them.
- A directory feature **MUST NOT** select Social Security numbers, birth dates, or Banner's internal person key or student ID. No check exists yet.
- Test fixtures **MUST** use synthetic people (AccessIDs shaped like `zz1234`, `@example.edu` emails), never copies of real records. No check exists yet, and none can be reliable, so this one stays a practice.

## [§6.4](https://creed.apps.wayne.edu/handbook/oracle-and-people.html#ldap) LDAP

- An LDAP connection **MUST** demand TLS (`LDAP_OPT_X_TLS_DEMAND`), never `NEVER`, `ALLOW` or `TRY`, and never a plain `ldap://` URI without StartTLS. No check exists yet.
- LDAP filters **MUST** be built with escaping (`ldap_escape()` or LdapRecord's query builder), never string formatting. No check exists yet.

Bound values only: With Arms Wide Open is how you greet a colleague, not how you build a query.

## [§6.5](https://creed.apps.wayne.edu/handbook/oracle-and-people.html#not-decided-here) Not decided here

- What counts as directory, employee or student data, and any confidentiality rule beyond Banner's flag: waiting on Institutional Research.
- Whether `raw-oci` becomes a fail for new apps: a later PROCTOR release decision.

Set from [`handbook/oracle-and-people.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/oracle-and-people.md), impression `65ac1d7`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
