---
title: The live PHP page
description: How /php reads the PHP lifecycle live from php.net, php-src, the php.net wiki, endoflife.date and Packagist, what it shows, and what happens when a source is down.
---

# The live PHP page

[`/php`](https://creed.apps.wayne.edu/php/) shows the whole PHP lifecycle as of now: the newest version, the latest
patch of every supported branch, the next release that is announced or tagged, a support timeline, and which PHP lines
the newest Laravel, CakePHP and Slim releases allow. Every value comes from a source at request time. There is no list
of versions, dates or branches anywhere in its code.

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
| php.net wiki `todo/phpNN` (raw export) | the GA day of a branch php.net does not list yet | server |
| endoflife.date `api/v1/products/php` | a cross-check of the dates; the branch list when php.net is down | server, browser |
| Packagist `p2` metadata | `require.php` of the newest release of each framework's two newest majors | server |

php.net and its news feed send no CORS headers, so the browser cannot read them itself. GitHub allows 60 calls an hour
per address without a token; the server makes one tag call per live PHP major plus one for the next major, at most every
ten minutes.

## How the server keeps it fresh

`deploy/public/php-feed.php` is a real file in the deploy layer, so the rewrite hands it to PHP instead of the static
front controller. It builds the document with `deploy/lib/PhpLifecycle`:

- A merged document younger than ten minutes is served as it is. An older one is refreshed by one request at a time
  (a non-blocking file lock); everyone else gets the cached copy meanwhile.
- php.net asks for its JSON to be cached for up to 30 days. The fetcher ignores that and asks for a fresh copy.
- A source that fails keeps its last good answer, marked stale with the time it was last read. A source that has never
  answered shows as down and contributes nothing.
- The cache lives in `CREED_PHP_FEED_CACHE` when that variable is set, otherwise in the system temp folder
  (`creed-php-lifecycle`).

## The merge rules

- Branches and dates come from php.net. When php.net is down they come from endoflife.date, and the page says so.
- When php.net and endoflife.date disagree on a date, the page shows php.net's and lists the disagreement.
- A release is a version php.net announced. A final php-src tag above it is "tagged in php-src, not announced". An
  alpha, beta or RC tag is never a release.
- An "Upcoming ... Security Release" notice is "announced", with its window when the text gives one. It disappears once
  a release dated on or after its day appears, or two days after its window.
- A new branch on the wiki's timetable shows its GA day as "on the wiki schedule".
- A branch that reached end of life in the last three years keeps its row, greyed: Wayne State apps still run them.

Each rule has a test on frozen copies of the feeds from 2026-11-19, the night 8.6.0 shipped
(`tests/PhpLifecycleMergeTest.php`, fixtures in `tests/fixtures/php-lifecycle/`).

## When the server cannot answer

If `php-feed.php` fails (or the server cannot reach out), the page reads endoflife.date and php-src's tags straight
from the browser, marks itself **Fallback** and says what it could not read: php.net's own pages, its news feed and the
framework fit. For that, the front controller's CSP lists `https://endoflife.date` and `https://api.github.com` in
`connect-src`. If both paths fail, the page says **No data** and shows nothing rather than something made up.

## Not there yet

A row for what Citadel offers. Citadel keeps image status in its dashboard, not in a public file, so there is no source
to read. The merger takes a `platforms` input for it already; a source that lists only images that are built fills it.
