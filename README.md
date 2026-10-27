# CREED

<!-- TODO(lead): GIF -->
TODO(lead): GIF (Creed band)

CREED is the Wayne State app stack's version policy, as data. One file,
`policy/policy.json`, lists the support dates for PHP, Laravel, Node, MySQL,
Vue and Inertia, plus the rules every check runs against. A small
framework-free PHP reader, `Waynestate\Creed\Policy`, answers the one question
every tool needs: what state is this version in, on this date?

No other code in the stack decides a version's state. The rules live in one
place, and every tool reads them from here.

## Install

```
composer require waynestate/creed
```

## Usage

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

### With Arms Wide Open

The support window stays open until `security_until`. A version is still
`Security` on that day, moves to `Eol` the day after, and stays in `Eol` for
`eol_grace_days` (90) more days before it is `EolGraceExpired`. The boundary
is inclusive, and the reader never guesses.

### Higher

The PHP floor is `^8.2` today. It moves to `^8.3` on 2027-01-01, and stack
packages raise their floor in a new major when a PHP version reaches EOL.

## The handbook

The rules, chapter by chapter, live in [`handbook/`](handbook/README.md).

## Tests

No PHP on the machine? Fine. Docker runs the suite:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli vendor/bin/phpunit
```

The policy validates against its schema:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer validate-policy
```

## Strongly recommended: a git.wayne.edu instance runner

git.wayne.edu has no runners today, so CI for this repo runs on GitLab. Every
product in the stack strongly recommends a git.wayne.edu instance runner. The
case for it is written up in `design/instance-runner-case.md` in the
coordination repo (`the stack`).

<!-- TODO(lead): GIF -->
TODO(lead): GIF (Creed Bratton)

Creed Bratton, whose quality assurance work we all admire. His past is a
mystery. Ours is in `policy/policy.json`, where it belongs.
