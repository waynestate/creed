---
title: "Quick start | CREED docs"
description: "The shortest path from composer require to asking the reader what state a version is in on a given day."
url: https://creed.apps.wayne.edu/docs/guides/quick-start.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Quick start[#](https://creed.apps.wayne.edu/docs/guides/quick-start.html#quick-start)

This page takes you from an empty project to two real answers from the CREED reader in four steps.

## Steps[#](https://creed.apps.wayne.edu/docs/guides/quick-start.html#steps)

1. **Require it.** Add CREED to your project.
   ```sh
   composer require waynestate/creed
   ```
2. **Load the bundled policy.** The package ships its policy file, and `Policy::bundled()` loads it.
   ```php
   use Waynestate\Creed\Policy;
   
   $policy = Policy::bundled();
   ```
   Expected result: a loaded policy. `origin()` says where it came from.
3. **Ask `stateOf`.** What state is PHP 8.2 in, on this date?
   ```php
   use Waynestate\Creed\State;
   
   $state = $policy->stateOf('php', '8.2', new DateTimeImmutable('2026-10-27'));
   // State::Security
   ```
   Expected result: `State::Security`. The answer is the state of that version on that date, nothing else.
4. **Ask `oldestSupported`.** What is the oldest PHP an app may declare?
   ```php
   $floor = $policy->oldestSupported('php', new DateTimeImmutable('2026-10-27'));
   // '8.2'
   ```
   Expected result: `'8.2'`, the lowest version still Active or Security.

## What you built[#](https://creed.apps.wayne.edu/docs/guides/quick-start.html#what-you-built)

Two questions, two stamped answers: the state of a version on a date, and the floor an app may declare. One last time: the reader answers, it does not guess.

## Next steps[#](https://creed.apps.wayne.edu/docs/guides/quick-start.html#next-steps)

- Read every public member in the [Reader API](https://creed.apps.wayne.edu/docs/reference/api.html).
- See how a version moves between states in [Support states and the grace period](https://creed.apps.wayne.edu/docs/guides/support-states.html).
- [Install](https://creed.apps.wayne.edu/docs/install.html) covers developing on CREED itself.

## Related[#](https://creed.apps.wayne.edu/docs/guides/quick-start.html#related)

- [Overview](https://creed.apps.wayne.edu/docs/index.html)
- [Install](https://creed.apps.wayne.edu/docs/install.html)
- [Reader API](https://creed.apps.wayne.edu/docs/reference/api.html)
- [Contributing](https://creed.apps.wayne.edu/docs/contributing.html)
