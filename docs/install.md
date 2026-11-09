---
title: Install
description: How to require waynestate/creed and develop on it, with the same commands in PowerShell and sh.
---

# Install

This page gets you from an empty project to a working CREED reader, and shows how to build and test CREED itself.

## Requirements

CREED requires PHP `^8.3`. Its `composer.json` says so, and pins the platform to `8.3.0` so installs resolve against that floor. The reader is framework-free, so nothing else is required at runtime.

## Install

Add the package to your project:

```sh
composer require waynestate/creed
```

### Develop on it

The same commands work in PowerShell and sh:

- Windows (PowerShell): `composer install`, then `composer ci` for everything CI runs, or `composer test` for just the tests.
- Linux and macOS (sh): the very same three commands, `composer install`, `composer ci`, `composer test`.

With only Docker (the same line works in PowerShell and sh):

```sh
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

The whole band fits in three commands, and nobody needs a solo.

## Verify

Load the bundled policy and ask it what state a version is in:

```php
use Waynestate\Creed\Policy;
use Waynestate\Creed\State;

$policy = Policy::bundled();

// What state is PHP 8.2 in, on this date?
$state = $policy->stateOf('php', '8.2', new DateTimeImmutable('2026-10-27'));
// State::Security
```

Expected result: `State::Security`. The reader answers, it does not guess, so if you get a different answer, the date or the policy file is not what you think it is.

## Next steps

Ask the reader two questions in the [Quick start](guides/quick-start.md), or read every public member in the [Reader API](reference/api.md).

## Related

- [Overview](index.md)
- [Quick start](guides/quick-start.md)
- [Reader API](reference/api.md)
- [Contributing](contributing.md)
