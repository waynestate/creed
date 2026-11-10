---
title: "CREED Handbook: 2 Dependencies"
description: "Chapter 2 of the CREED handbook, the written rules for the Wayne State app stack: Dependencies."
url: https://creed.apps.wayne.edu/handbook/dependencies.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Chapter 2

# Dependencies

1 section · 16 provisions · **MUST NOT** 5 · **MUST** 8 · **SHOULD NOT** 1 · **SHOULD** 3

Decided

Key words The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT** and **MAY** in this chapter are to be interpreted as described in [RFC 2119](https://www.rfc-editor.org/rfc/rfc2119).

- No `dev-master`, `dev-*` or `*` constraints on runtime dependencies (the stack's version policy). [PROCTOR](https://proctor.apps.wayne.edu/) check: `unbounded-constraints`.
- A lock file is required and **MUST** be committed (the stack's version policy). PROCTOR check: `lock-committed`.
- `composer audit` **MUST** report no advisories at or above the policy's severity threshold (the stack's version policy). PROCTOR check: `composer-audit`.
- The policy's [`audit_severity_threshold`](https://github.com/waynestate/creed/blob/main/policy/policy.json) uses Composer's words. npm's `audit-level` reads them as: `low` is `low`, `medium` is `moderate`, `high` is `high`, `critical` is `critical` (npm's `info` has no Composer word). A tool that reads the threshold for npm **MUST** apply this map (the stack's version policy). Held by: [Preflight](https://preflight.apps.wayne.edu/)'s `npm-audit` component (`medium` means `moderate`).

CREED's [`abandoned_packages`](https://github.com/waynestate/creed/blob/main/policy/policy.json) rule is `fail`, matching Composer's default since 2.7, so `composer audit` fails on an abandoned package. An app that has to keep one replaces it or records an override with a reason; PROCTOR's `composer-audit` check applies the same setting.

- `composer.json` `require.php` **MUST NOT** allow an EOL version as its lowest bound (the stack's version policy). PROCTOR check: `php-floor`.
- Apps **SHOULD** set a Composer cooldown of 3 days (`config.policy.cooldown.period`, Composer 2.11; the stack's dependency decision, 2026-11-03) and exempt the stack's own packages (`"ignore": ["waynestate/*"]`), which the stack's CI tests before tagging. [Sourdough](https://sourdough.apps.wayne.edu/)'s shapes set both. No check exists yet: a PROCTOR check for a missing cooldown is planned.
- Every project is a framework-free core plus thin adapters. `composer.json` requires only `php` and PSR packages; Laravel, Inertia and Vue are `suggest` (the stack's core-plus-adapters design).
- Adapters **MUST** call the core and **MUST NOT** re-implement it (the stack's core-plus-adapters design). No check exists yet.
- Before adding a third-party package, check it comes from a trusted source, and where the source is open, read enough of it to judge it sound and fit (**SHOULD**). The audit and abandoned-package rules in this chapter apply after that choice (WSU developer standards, 2024). No check exists yet.

## [§2.1](https://creed.apps.wayne.edu/handbook/dependencies.html#private-packages) Private packages

- Internal-only packages **MUST** be installed from one GitLab package registry per ecosystem (Composer, npm), not from one `vcs`, `path` or `artifact` repository entry per package (the stack's private-packages decision). No check exists yet.
- Credentials **MUST NOT** appear in any repository URL in `composer.json` or `composer.lock`, or as a literal value in a committed `.npmrc`. Tokens live in a gitignored `auth.json`, the npm user config, or a CI or build-time secret (the stack's private-packages decision). PROCTOR check: `repo-url-credentials`.
- Builds **MUST** use a read-only deploy token (`read_package_registry` only); personal access tokens **MUST NOT** be used in builds (the stack's private-packages decision). No check exists yet.
- The private Composer registry entry **MUST** be canonical, and the Packagist entry **SHOULD** exclude the internal vendor names, so a public package with the same name can never be installed instead (the stack's private-packages decision). No check exists yet.
- A released version **MUST** map to one commit for ever: tags are protected, and a version is never republished from a different commit (the stack's private-packages decision). Held by: Preflight's `package-publish` component, which reads the registry first and refuses a version that already exists with different contents.
- Public code **MUST NOT** go through the private registry; it is published to Packagist or npm (the stack's private-packages decision). No check exists yet.
- Apps **SHOULD NOT** proxy or mirror public registries in v1 (the stack's private-packages decision). No check exists yet.

Today PROCTOR's `repo-url-credentials` check holds rule 2 and Preflight's `package-publish` component holds rule 5. Checks for rule 1 (one registry per ecosystem) and rule 4 (the canonical entry and the Packagist exclusion) are planned, not built: earlier versions of this chapter named them as `package-sources` and `internal-vendor-shadowing`, but PROCTOR has never shipped either.

In [`policy.json`](https://github.com/waynestate/creed/blob/main/policy/policy.json)

- **`rules.abandoned_packages`**: `"fail"`
- **`rules.audit_severity_threshold`**: `"high"`

Set from [`handbook/dependencies.md` on GitHub](https://github.com/waynestate/creed/blob/main/handbook/dependencies.md), impression `4c44752`. Found a mistake? Fix the Markdown; this page is rebuilt from it.
