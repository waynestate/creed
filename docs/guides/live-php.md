---
title: The live PHP page
description: How /php reads the PHP lifecycle live from php.net, php-src, the php.net wiki, endoflife.date, Packagist, Laravel's support table and CREED's own framework tests, what it shows (every release's timeline included), and what happens when a source is down.
---

# The live PHP page

[`/php`](https://creed.apps.wayne.edu/php/) shows the whole PHP lifecycle as of now: the newest version, the latest
patch of every supported branch, the next release that is announced or tagged (or, when nothing is, the lines still
to come), a support timeline, and which PHP lines the newest Laravel, CakePHP and Slim releases allow and actually
test. Below that
dashboard sits every release's own timeline, from its first alpha to its last security fix. Every value comes from a
source at request time. There is no list of versions, dates or branches anywhere in its code.

It is a view, not a gate. PROCTOR, HELMET and the rest read `policy/policy.json`, which the policy robot refreshes on a
schedule and a person can review. The live page exists for the hours in between: the morning a new branch ships, or
the evening before a security release.

## What it reads

| Source | What it gives the page | Read by |
|---|---|---|
| php.net `releases/states.php` | every branch, its state, its release, active support and security support dates | server |
| php.net `releases/active.php` | the newest release of each supported branch, its date, whether it is a security release | server |
| php.net `feed.atom` | "Released!" posts, and the "Upcoming ... Security Release" notices with their schedule | server |
| php-src tags (GitHub `git/matching-refs`) | final tags not yet announced, and release candidates | server, browser |
| php-src master `main/php_version.h` | the version master is building (`8.7.0-dev`): the next line in development | server |
| php.net wiki `todo` index (raw export) | which release pages exist: `todo:php86`, `todo:php85` and so on back to 5.2 | server |
| php.net wiki `todo/phpNN` (raw export) | each release's timetable: alphas, betas, RCs, freezes, GA, skipped steps and their Announce links | server |
| endoflife.date `api/v1/products/php` | a cross-check of the dates; the branch list when php.net is down | server, browser |
| Packagist `p2` metadata | `require.php` of the newest release of each framework's two newest majors | server |
| Laravel's `releases.md` in laravel/docs, on the newest major's branch (`raw.githubusercontent.com`) | the Support Policy table: the PHP lines Laravel announces for each major (Laravel 13: 8.3 - 8.5) | server |
| `data/framework-php-tests.json` in the deployed tree | CREED's own test of each framework's official skeleton on each PHP line Composer allows ([Our framework tests](framework-php-tests.md)) | server |

php.net and its news feed send no CORS headers, so the browser cannot read them itself. GitHub allows 60 calls an hour
per address without a token; the server makes one tag call per live PHP major plus one for the next major, at most
every ten minutes. php-src master's version file and Laravel's releases.md are raw files, outside that budget.

The wiki is read gently. Release pages are found only through the index, never by guessing names, and asked for one at
a time, at least a second apart; the first page that does not answer ends that round. The index, the pages of
supported releases and the pages of releases still to come are read again on the ten-minute schedule. A closed
release's timetable does not change, so it is read once and kept: at most three a round until all are in, and the
sources list shows them as one archive line.

How long a release is supported is PHP's
[release cycle policy](https://wiki.php.net/rfc/release_cycle_update). The page cites it and never uses it to work out
a date php.net has not published.

## How the server keeps it fresh

`deploy/public/php-feed.php` is a real file in the deploy layer, so the rewrite hands it to PHP instead of the static
front controller. It builds the document with `deploy/lib/PhpLifecycle`:

- A merged document younger than ten minutes is served as it is. An older one is refreshed by one request at a time
  (a non-blocking file lock); everyone else gets the cached copy meanwhile.
- php.net asks for its JSON to be cached for up to 30 days. The fetcher ignores that and asks for a fresh copy.
- A source that fails keeps its last good answer, marked stale with the time it was last read. A source that has never
  answered shows as down and contributes nothing.
- The page and its script always match. `deploy/public/index.php` serves HTML uncached and scripts, styles and data
  (`.js`, `.css`, `.json`, `.md`, `.txt`, `.xml`) with `Cache-Control: no-cache` and an ETag, so a browser asks on
  every load and gets a 304 when nothing changed. Images and fonts keep an hour. Before 2026-11-20 scripts were cached
  for an hour: right after a deploy, a browser that had opened `/php` earlier ran the old script on the new feed and
  showed "tested lines not read" in every row. `tests/DeployLayoutTest.php` runs the front controller and holds this.
- The cache lives in `CREED_PHP_FEED_CACHE` when that variable is set, otherwise in the system temp folder
  (`creed-php-lifecycle`).

## The merge rules

- Branches and dates come from php.net. When php.net is down they come from endoflife.date, and the page says so.
- When php.net and endoflife.date disagree on a date, the page shows php.net's and lists the disagreement.
- A release is a version php.net announced. A final php-src tag above it is "tagged in php-src, not announced". An
  alpha, beta or RC tag is never a release.
- An "Upcoming ... Security Release" notice is "announced", with its window when the text gives one. It disappears once
  a release dated on or after its day appears, or two days after its window.
- A new branch on the wiki's timetable shows its GA day as "on the wiki schedule", and its next step (the next RC, say)
  with its date.
- A branch that reached end of life in the last three years keeps its row, greyed: Wayne State apps still run them.

### Lines with no timeline yet

Before PHP publishes anything about a release, the page says so and stops there. It never estimates.

- The line master is building (8.7 while master says `8.7.0-dev`) shows as "next in development: no timeline released
  yet". It is PHP's yearly feature release: a minor by PHP's numbering, but it brings deprecations and breaking changes.
- The next major (9.0) shows as "next major: no timeline released yet". When master itself is on an `X.0` version, that
  line is the major and no second one is shown.
- Each row says what the page is watching (php-src master, the wiki index, and for the major the RFC index) and when it
  last checked.
- The row goes away by itself when a wiki timetable page for that release appears in the index, an alpha tag lands in
  php-src, or php.net posts a notice. A timetable replaces it with its real dates.
- When master cannot be read, there are no such rows: the card says "Nothing announced or tagged", as before.

### Release timelines

Every branch php.net lists, every release page in the wiki index, and every line still to come gets a timeline,
newest first:

- The milestones are the wiki's timetable rows: alphas, betas, RCs, feature freezes and the notes in between, each with
  its date and the official Announce link. A struck-out row shows as skipped (8.6 skipped RC1, and tagged RC6 and then
  skipped it).
- General availability, the end of active support and the end of security support are php.net's dates, joined onto
  the same timeline. Each step is done or still ahead against today, and the next one is marked.
- Once a release is out, its pre-release run folds into one line that opens to show every step.
- Patches: the past ones are what php.net and php-src say happened (the last few with their dates, and the count of
  tags). A future patch day shows only when php.net has announced it. Otherwise the card says, in one quiet line, that
  no date is published. The page never projects PHP's four-week patch rhythm.
- Releases past end of life sit under "Older releases", complete, one click away. A closed release whose page has not
  been read yet says so until its turn comes.

### Framework fit: supported means announced and passed our test

A `require.php` such as `^8.3` or `>=8.2` lets Composer install the framework on every PHP line to come, including
ones nobody has announced or tried. A framework's own CI matrix is not an announcement either: CakePHP 5.4.4 "added
PHP 8.6 to the CI matrix" without saying it supports 8.6. So each cell joins three answers and reads one of five states:

| State | When | On the page |
|---|---|---|
| **Supported** (`supported`) | Laravel announced the line AND our own test of the official skeleton passed on it, with or without deprecations | the filled cloth disc, "Supported: announced and passed our test" |
| **Announced, not passed** (`announced-untested`) | Laravel announced the line, but our test is missing, failed or was refused; the cell's title says which | an empty solid ring in the same cloth, "Announced, not passed our test yet" |
| **Allowed** (`allowed`) | Composer allows the line and Laravel's table does not announce it (Laravel 13 and 12 on 8.6, today) | a dashed pencil ring, "Allowed by Composer, support not announced" |
| **Unknown** (`unknown`) | Composer allows the line and there is no announcement a server can read: CakePHP and Slim always, Laravel when its table cannot be read | a dashed square with a question mark, "Allowed by Composer, support not published in a readable form" |
| **No** (`no`) | `require.php` refuses the line, whatever anyone announced or tested | a cross, "Not allowed" |

Our test alone never makes a cell green, and it never makes one red: a Laravel 12 cell that failed our test on 8.6 is
still **Allowed**, because Laravel has not announced 8.6, and the failure shows in its title. Every cell's title and
screen-reader text give our test's result, for CakePHP and Slim too: "our test: pass on PHP 8.5.13, Laravel 13.43.0,
2026-11-20", "our test, 2026-11-20: fail at tests: ..." or "our test: not run".

**Where the announcement comes from.** Laravel only. The feed reads `releases.md` from laravel/docs on the branch of
the newest Laravel major Packagist knows (`13.x` today; older branches lag), takes the rows of the Support Policy
table (`| 13 | 8.3 - 8.5 | ...`, only under that heading) and expands each range to its lines. Which file belongs to
Laravel is configuration in `Feed::ANNOUNCED`; the lines come out of the file. CakePHP and Slim publish their PHP
support as book pages and changelog prose (research in the coordination repo), so they have no entry and show as
**Unknown**. No list of announcements is kept by hand.

**Fail-safe.** The table counts only when this refresh read and parsed it. When the fetch fails, the layout changes so
no row parses, or the table has no row for a major, that major's cells drop to **Unknown**: never green from an older
read, never red. The feed says so: `frameworks[].announced_ok` is false with `announced_reason` (`timed out`, `the
Support Policy table did not parse`, `the Support Policy table has no row for Laravel 14`), the row shows "support
table not read, see below", and a line under the table names the source and the reason in plain words.

**Where our test comes from.** `data/framework-php-tests.json`, committed and shipped with the deployed tree (Citadel
deploys the whole checkout; the feed reads it from beside `deploy/`), read on every refresh. See
[Our framework tests](framework-php-tests.md) for how it is made. When the file is missing or is not the matrix, every
cell's test reads "not run", so no cell is **Supported**. `framework_tests.reason` says why (`data/framework-php-tests.json
is not in the deployed tree`, `... could not be read on the server` or `... is not a framework test matrix`), and the
same words show under the table. `tests/DeployLayoutTest.php` holds the path: `Feed::defaultTestsFile()` must resolve
to the committed file, and it must parse.

The feed carries, per framework major: `allows` (unchanged), `announced` (the same shape keyed by PHP line, null when
nothing readable was announced), `announced_lines`, `announced_source` (the raw URL read), `announced_link` (the
rendered table), `announced_fetched_at`, `announced_ok` (true, false, or null for a framework with no readable source),
`announced_reason`, `our_test` (per line: state, PHP patch, framework version, date, failing step and first error, or
null when not run) and `fit`. The document carries `framework_tests` (whether the results file was read, the reason
when it was not, its date, and links to it and its docs page).
### Legacy frameworks

CakePHP and Slim are being phased out (department rule D-124: new apps use raw PHP or Laravel). Their rows in the
framework fit sit under a "Legacy: being phased out" band, styled apart from the support colors, with the note "Don't
start new apps on CakePHP or Slim. New apps use raw PHP or Laravel." and a link to Booster's framework hops guide.
Their fit is read from Packagist and our tests as Laravel's is; with no readable announcement, a line Composer allows is **Unknown**. The label lives in the feed (`frameworks[].lifecycle`), not
in `policy/policy.json`: the policy schema allows no extra component fields, and an older schema copy would refuse a
policy file that carried one.

Each rule has a test on frozen copies of the feeds from 2026-11-19, the night 8.6.0 shipped, and of the wiki from
2026-11-20 (`tests/PhpLifecycleMergeTest.php`, `tests/PhpLifecycleFeedTest.php`, `tests/PhpLifecycleParseTest.php`,
fixtures in `tests/fixtures/php-lifecycle/`). Laravel's `releases.md` is a trimmed copy of the 13.x branch read on
2026-11-20, with two made-up variants labelled as made up (the table rewritten as a list, which must not parse, and the
table without its Laravel 13 row); the test results are a copy of `data/framework-php-tests.json` from 2026-11-20. One fixture, a made-up 8.7 timetable, is labelled as made up; it proves a
new page in the index is picked up and replaces "no timeline released yet" with its dates.

## When the server cannot answer

If `php-feed.php` fails (or the server cannot reach out), the page reads endoflife.date and php-src's tags straight
from the browser, marks itself **Fallback** and says what it could not read: php.net's own pages, its news feed,
php-src master, the wiki's timetables and the framework fit (Packagist, Laravel's support table and our test results are read on
the server only). For that, the front controller's CSP lists
`https://endoflife.date` and `https://api.github.com` in `connect-src`. If both paths fail, the page says **No data**
and shows nothing rather than something made up.

## Not there yet

A row for what Citadel offers. Citadel keeps image status in its dashboard, not in a public file, so there is no source
to read. The merger takes a `platforms` input for it already; a source that lists only images that are built fills it.
