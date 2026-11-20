---
title: "The live PHP page | CREED docs"
description: "How /php reads the PHP lifecycle live from php.net, php-src, the php.net wiki, endoflife.date, Packagist and the frameworks' CI workflows, what it shows (every release's timeline included), and what happens when a source is down."
url: https://creed.apps.wayne.edu/docs/guides/live-php.html
llms: https://creed.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# The live PHP page [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#the-live-php-page)

[`/php`](https://creed.apps.wayne.edu/php/) shows the whole PHP lifecycle as of now: the newest version, the latest patch of every supported branch, the next release that is announced or tagged (or, when nothing is, the lines still to come), a support timeline, and which PHP lines the newest Laravel, CakePHP and Slim releases allow and actually test. Below that dashboard sits every release's own timeline, from its first alpha to its last security fix. Every value comes from a source at request time. There is no list of versions, dates or branches anywhere in its code.

It is a view, not a gate. PROCTOR, HELMET and the rest read `policy/policy.json`, which the policy robot refreshes on a schedule and a person can review. The live page exists for the hours in between: the morning a new branch ships, or the evening before a security release.

## What it reads [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#what-it-reads)

| Source | What it gives the page | Read by |
| --- | --- | --- |
| php.net `releases/states.php` | every branch, its state, its release, active support and security support dates | server |
| php.net `releases/active.php` | the newest release of each supported branch, its date, whether it is a security release | server |
| php.net `feed.atom` | "Released!" posts, and the "Upcoming ... Security Release" notices with their schedule | server |
| php-src tags (GitHub `git/matching-refs`) | final tags not yet announced, and release candidates | server, browser |
| php-src master `main/php_version.h` | the version master is building (`8.7.0-dev`): the next line in development | server |
| php.net wiki `todo` index (raw export) | which release pages exist: `todo:php86`, `todo:php85` and so on back to 5.2 | server |
| php.net wiki `todo/phpNN` (raw export) | each release's timetable: alphas, betas, RCs, freezes, GA, skipped steps and their Announce links | server |
| endoflife.date `api/v1/products/php` | a cross-check of the dates; the branch list when php.net is down | server, browser |
| Packagist `p2` metadata | `require.php` of the newest release of each framework's two newest majors | server |
| Each framework's GitHub Actions workflow on the major's branch (`raw.githubusercontent.com`) | the PHP lines that major's own CI runs: Laravel `tests.yml`, CakePHP `ci.yml`, Slim `tests.yml`, on `13.x`, `5.x`, `3.x` and so on | server |

php.net and its news feed send no CORS headers, so the browser cannot read them itself. GitHub allows 60 calls an hour per address without a token; the server makes one tag call per live PHP major plus one for the next major, at most every ten minutes. php-src master's version file and the frameworks' workflows are raw files, outside that budget.

The wiki is read gently. Release pages are found only through the index, never by guessing names, and asked for one at a time, at least a second apart; the first page that does not answer ends that round. The index, the pages of supported releases and the pages of releases still to come are read again on the ten-minute schedule. A closed release's timetable does not change, so it is read once and kept: at most three a round until all are in, and the sources list shows them as one archive line.

How long a release is supported is PHP's [release cycle policy](https://wiki.php.net/rfc/release_cycle_update). The page cites it and never uses it to work out a date php.net has not published.

## How the server keeps it fresh [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#how-the-server-keeps-it-fresh)

`deploy/public/php-feed.php` is a real file in the deploy layer, so the rewrite hands it to PHP instead of the static front controller. It builds the document with `deploy/lib/PhpLifecycle`:

- A merged document younger than ten minutes is served as it is. An older one is refreshed by one request at a time (a non-blocking file lock); everyone else gets the cached copy meanwhile.
- php.net asks for its JSON to be cached for up to 30 days. The fetcher ignores that and asks for a fresh copy.
- A source that fails keeps its last good answer, marked stale with the time it was last read. A source that has never answered shows as down and contributes nothing.
- The cache lives in `CREED_PHP_FEED_CACHE` when that variable is set, otherwise in the system temp folder (`creed-php-lifecycle`).

## The merge rules [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#the-merge-rules)

- Branches and dates come from php.net. When php.net is down they come from endoflife.date, and the page says so.
- When php.net and endoflife.date disagree on a date, the page shows php.net's and lists the disagreement.
- A release is a version php.net announced. A final php-src tag above it is "tagged in php-src, not announced". An alpha, beta or RC tag is never a release.
- An "Upcoming ... Security Release" notice is "announced", with its window when the text gives one. It disappears once a release dated on or after its day appears, or two days after its window.
- A new branch on the wiki's timetable shows its GA day as "on the wiki schedule", and its next step (the next RC, say) with its date.
- A branch that reached end of life in the last three years keeps its row, greyed: Wayne State apps still run them.

### Lines with no timeline yet [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#lines-with-no-timeline-yet)

Before PHP publishes anything about a release, the page says so and stops there. It never estimates.

- The line master is building (8.7 while master says `8.7.0-dev`) shows as "next in development: no timeline released yet". It is PHP's yearly feature release: a minor by PHP's numbering, but it brings deprecations and breaking changes.
- The next major (9.0) shows as "next major: no timeline released yet". When master itself is on an `X.0` version, that line is the major and no second one is shown.
- Each row says what the page is watching (php-src master, the wiki index, and for the major the RFC index) and when it last checked.
- The row goes away by itself when a wiki timetable page for that release appears in the index, an alpha tag lands in php-src, or php.net posts a notice. A timetable replaces it with its real dates.
- When master cannot be read, there are no such rows: the card says "Nothing announced or tagged", as before.

### Release timelines [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#release-timelines)

Every branch php.net lists, every release page in the wiki index, and every line still to come gets a timeline, newest first:

- The milestones are the wiki's timetable rows: alphas, betas, RCs, feature freezes and the notes in between, each with its date and the official Announce link. A struck-out row shows as skipped (8.6 skipped RC1, and tagged RC6 and then skipped it).
- General availability, the end of active support and the end of security support are php.net's dates, joined onto the same timeline. Each step is done or still ahead against today, and the next one is marked.
- Once a release is out, its pre-release run folds into one line that opens to show every step.
- Patches: the past ones are what php.net and php-src say happened (the last few with their dates, and the count of tags). A future patch day shows only when php.net has announced it. Otherwise the card says, in one quiet line, that no date is published. The page never projects PHP's four-week patch rhythm.
- Releases past end of life sit under "Older releases", complete, one click away. A closed release whose page has not been read yet says so until its turn comes.

### Framework fit: tested, allowed, or no [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#framework-fit-tested-allowed-or-no)

A `require.php` such as `^8.3` or `>=8.2` lets Composer install the framework on every PHP line to come, including ones the framework has never run. So each cell joins two sources and reads one of three states:

| State | When | On the page |
| --- | --- | --- |
| **Tested** | `require.php` allows the line and the major's own CI runs it | the filled cloth disc |
| **Allowed, not tested yet** | `require.php` allows the line, the CI does not run it: Composer would install it, but the framework does not test it yet (Laravel 13 on 8.6 the day 8.6 shipped) | a dashed pencil ring, never the fit color |
| **No** | `require.php` refuses the line | a cross |

The CI part is read from the framework's GitHub Actions workflow on that major's branch (`13.x` for Laravel 13). Which repository and file belong to each framework is configuration in `Feed::WORKFLOWS`; the PHP lines come out of the file. Every PHP value counts: the matrix list (`php:` or `php-version:`, quoted or not, flow or block form) and `include:` entries. An `exclude:` entry drops a line only when it removes every combination that line was in, so Laravel 13's exclusion of one PHPUnit pairing on 8.3 does not drop 8.3. `nightly` and expressions are not lines. A line the CI runs but the constraint refuses is still **No**: Slim 4 runs 8.6 with `--ignore-platform-req`, and Composer would not install it there.

The feed carries all three: `frameworks[].allows` (unchanged), `frameworks[].tested` (the same shape, keyed by PHP line, true where the CI runs it), `frameworks[].tested_lines`, `frameworks[].tested_source` (the workflow's URL) and `frameworks[].fit` (`tested`, `untested`, `allowed` or `no` per line). When a workflow cannot be read, `tested` is null, `fit` says `allowed` wherever Composer allows the line, and the row shows a thin ring and "tested lines not read": the page never claims Tested without having read the CI.

### Legacy frameworks [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#legacy-frameworks)

CakePHP and Slim are being phased out (department rule D-124: new apps use raw PHP or Laravel). Their rows in the framework fit sit under a "Legacy: being phased out" band, styled apart from the support colors, with the note "Don't start new apps on CakePHP or Slim. New apps use raw PHP or Laravel." and a link to Booster's framework hops guide. Their fit is read from Packagist and their CI exactly as Laravel's is. The label lives in the feed (`frameworks[].lifecycle`), not in `policy/policy.json`: the policy schema allows no extra component fields, and an older schema copy would refuse a policy file that carried one.

Each rule has a test on frozen copies of the feeds from 2026-11-19, the night 8.6.0 shipped, and of the wiki from 2026-11-20 (`tests/PhpLifecycleMergeTest.php`, `tests/PhpLifecycleFeedTest.php`, `tests/PhpLifecycleParseTest.php`, fixtures in `tests/fixtures/php-lifecycle/`). The framework workflows are trimmed copies read on 2026-11-20, with one made-up workflow for the exclude rules, labelled as made up. One fixture, a made-up 8.7 timetable, is labelled as made up; it proves a new page in the index is picked up and replaces "no timeline released yet" with its dates.

## When the server cannot answer [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#when-the-server-cannot-answer)

If `php-feed.php` fails (or the server cannot reach out), the page reads endoflife.date and php-src's tags straight from the browser, marks itself **Fallback** and says what it could not read: php.net's own pages, its news feed, php-src master, the wiki's timetables and the framework fit (Packagist and the frameworks' CI workflows are read on the server only). For that, the front controller's CSP lists `https://endoflife.date` and `https://api.github.com` in `connect-src`. If both paths fail, the page says **No data** and shows nothing rather than something made up.

## Not there yet [#](https://creed.apps.wayne.edu/docs/guides/live-php.html#not-there-yet)

A row for what Citadel offers. Citadel keeps image status in its dashboard, not in a public file, so there is no source to read. The merger takes a `platforms` input for it already; a source that lists only images that are built fills it.
