---
title: "The instance runner | CREED docs"
description: "Why every product in the stack still strongly recommends a git.wayne.edu instance runner, now that git.wayne.edu has one and the stack's CI runs on it."
url: https://creed.apps.wayne.edu/docs/guides/instance-runner.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The instance runner[#](https://creed.apps.wayne.edu/docs/guides/instance-runner.html#the-instance-runner)

This page explains why every product in the stack strongly recommends a git.wayne.edu instance runner, and where CREED's code lives.

## Strongly recommended: a git.wayne.edu instance runner[#](https://creed.apps.wayne.edu/docs/guides/instance-runner.html#strongly-recommended-a-git-wayne-edu-instance-runner)

git.wayne.edu now has an instance runner, and the stack's CI runs on it. Every product in the stack still strongly recommends a git.wayne.edu instance runner, so that capacity stays there.

![Creed Bratton from The Office in a dark suit and blue shirt with a wide, wild-eyed grin](https://creed.apps.wayne.edu/docs/readme/creed-bratton.gif)

Creed Bratton, whose quality assurance work we all admire. His past is a mystery. Ours is in `policy/policy.json`, where it belongs.

## Where the code lives[#](https://creed.apps.wayne.edu/docs/guides/instance-runner.html#where-the-code-lives)

The canonical repository is [enterprise-apps/creed](https://git.wayne.edu/enterprise-apps/creed) on git.wayne.edu. Open issues and merge requests there; tags are made there, by the maintainer, with an approver. CI runs on git.wayne.edu. GitHub ([waynestate/creed](https://github.com/waynestate/creed)) is a read-only push mirror of it: nobody pushes to it, and Packagist reads from it.

Creed Bratton would say the code lives wherever he last left it. Ours lives on git.wayne.edu.

## Related[#](https://creed.apps.wayne.edu/docs/guides/instance-runner.html#related)

- [Carrying CREED in another package](https://creed.apps.wayne.edu/docs/guides/carrying-creed.html)
- [The handbook](https://creed.apps.wayne.edu/docs/guides/handbook.html)
- [Contributing](https://creed.apps.wayne.edu/docs/contributing.html)
