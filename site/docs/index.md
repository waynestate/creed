---
title: "Overview | CREED docs"
description: "What CREED is: the Wayne State App Stack Code, one policy file, one reader, and an inspector who stamps what he finds."
url: https://creed.apps.wayne.edu/docs/
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

[![CREED's front page: the title page of the Wayne State App Stack Code, 2026 edition, with clause §0.1 spelling out the name, and a green cloth-bound book stamped with CREED's keystone arch mark.](https://creed.apps.wayne.edu/docs/readme/hero.jpg)](https://creed.apps.wayne.edu/index.html)

# CREED [#](https://creed.apps.wayne.edu/docs/#creed)

![php ^8.3](https://creed.apps.wayne.edu/docs/readme/badges/php.svg) ![license MIT](https://creed.apps.wayne.edu/docs/readme/badges/license.svg) ![policy schema v1, 6 components](https://creed.apps.wayne.edu/docs/readme/badges/policy.svg)

**The standard everything rests on.**

**Not to be confused with:** CREED is the rules; PROCTOR checks an app against them.

This page orients you: what CREED is, what it ships, and where to go next.

CREED is the Wayne State app stack's version policy, as data. One file, `policy/policy.json`, lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against. A small framework-free PHP reader, `Waynestate\Creed\Policy`, answers the one question every tool needs: what state is this version in, on this date?

## Usage [#](https://creed.apps.wayne.edu/docs/#usage)

```php
use Waynestate\Creed\Policy;
use Waynestate\Creed\State;

$policy = Policy::bundled();

// What state is PHP 8.2 in, on this date?
$state = $policy->stateOf('php', '8.2', new DateTimeImmutable('2026-10-27'));
// State::Security

// What is the oldest PHP an app may declare?
$floor = $policy->oldestSupported('php', new DateTimeImmutable('2026-10-27'));
// '8.2'
```

## Pick your path [#](https://creed.apps.wayne.edu/docs/#pick-your-path)

- [Quick start](https://creed.apps.wayne.edu/docs/guides/quick-start.html): the shortest path from `composer require` to a stamped answer.
- [Install](https://creed.apps.wayne.edu/docs/install.html): require the package, verify the bundled policy, develop on it.
- [Reader API](https://creed.apps.wayne.edu/docs/reference/api.html): every public member of the reader, one line each.
- [Handbook](https://creed.apps.wayne.edu/docs/guides/handbook.html): the rules, chapter by chapter.

Status: version 0.1.11, MIT license.

Agents: fetch [https://creed.apps.wayne.edu/llms.txt](https://creed.apps.wayne.edu/llms.txt).

One command, two clouds, zero excuses. Higher.

![Singer of the band Creed in a white tank top and black leather pants on a stage with a red backdrop, one arm stretched out over a cheering crowd](https://creed.apps.wayne.edu/docs/readme/creed-band.gif)

## Related [#](https://creed.apps.wayne.edu/docs/#related)

- [Install](https://creed.apps.wayne.edu/docs/install.html)
- [Quick start](https://creed.apps.wayne.edu/docs/guides/quick-start.html)
- [Reader API](https://creed.apps.wayne.edu/docs/reference/api.html)
- [Contributing](https://creed.apps.wayne.edu/docs/contributing.html)
- [Changelog](https://creed.apps.wayne.edu/docs/changelog.html)
