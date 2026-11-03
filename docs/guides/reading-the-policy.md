---
title: Reading the policy from PHP
description: The reader's public members, one line each, from Policy::bundled() to belowOldestLineReason().
---

# Reading the policy from PHP

This page shows how a PHP program loads the CREED policy and asks it the one question every tool needs: what state is this version in, on this date?

## Load a policy

CREED is the Wayne State app stack's version policy, as data. One file, `policy/policy.json`, lists the support dates for PHP, Laravel, Node, MySQL, Vue and Inertia, plus the rules every check runs against. A small framework-free PHP reader, `Waynestate\Creed\Policy`, answers the questions:

- `Policy::bundled()`, `Policy::fromFile($path)`, `Policy::fromArray($data)`: load a policy; `origin()` says where it came from.

## The example

One snippet covers the whole round trip:

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

## stateOf

`stateOf($component, $version, $on)`: the state, or an exception; `tryStateOf()` is the same answer as `null` when it cannot be answered. In the example, PHP 8.2 on 2026-10-27 is `State::Security`.

## oldestSupported

`oldestSupported($component, $on)`: the lowest version still Active or Security; `supported()` lists all of them, oldest first. In the example, the oldest PHP an app may declare on 2026-10-27 is `'8.2'`.

> [!TIP]
> One last time: the reader answers, it does not guess.

## Related

- [Support states and the grace period](support-states.md)
- [Reader API](../reference/api.md)
- [Install](../install.md)
- [Quick start](quick-start.md)
