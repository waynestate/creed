<?php

declare(strict_types=1);

namespace Waynestate\Creed\Site\PhpLifecycle;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Merges what the sources said into the one document the /php page reads.
 *
 * The rules, each with a test on frozen fixtures (tests/PhpLifecycleMergeTest.php):
 *  - Branches and their dates come from php.net (states.php). When php.net is
 *    down they come from endoflife.date, and the document says so.
 *  - When php.net and endoflife.date disagree on a date, php.net wins and the
 *    disagreement is listed, never hidden.
 *  - A release is a version php.net announced (active.php or a "Released!"
 *    news entry); endoflife.date stands in when php.net has nothing for a branch.
 *  - A final php-src tag above the newest announced release is "tagged, not
 *    announced". An alpha, beta or RC tag is never a release: at most
 *    "release candidate tagged".
 *  - An "Upcoming ... Release" notice on php.net news is "announced", with its
 *    window when the text gives one; it goes away once a release dated on or
 *    after its day appears, or two days after its window.
 *  - A new branch on the wiki's timetable shows its GA day as "on the wiki
 *    schedule". The timetables come from the wiki's index of release pages.
 *  - php-src master's php_version.h names the line in development (8.7.0-dev
 *    means 8.7 comes next). It and the major after it are "no-timeline" until
 *    a wiki timetable, a php-src tag or a php.net notice covers them. Nothing
 *    is estimated from the release-cycle policy.
 *  - Every release gets a timeline: the wiki's milestones (skipped steps kept
 *    and marked) joined with php.net's GA, active and security end dates, and
 *    the patch releases that happened. A future patch day comes only from a
 *    php.net notice; the four-week rhythm is never projected.
 * A source that is missing contributes nothing. Nothing here is invented.
 */
final class Merge
{
    public const SCHEMA = 1;

    /** A branch that reached EOL within this many days still gets a row: WSU apps still run them. */
    public const RECENT_EOL_DAYS = 1100;

    /** A branch first released within this many days carries the "New" mark. */
    public const NEW_DAYS = 30;

    /** A notice stays up this long after its window when no release has appeared. */
    private const NOTICE_GRACE_HOURS = 48;

    /** Where the evidence for a line with no timeline points. */
    public const MASTER_LINK = 'https://github.com/php/php-src/blob/master/main/php_version.h';

    public const INDEX_LINK = 'https://wiki.php.net/todo';

    public const RFC_LINK = 'https://wiki.php.net/rfc';

    /** How many dated patch releases a timeline lists; the rest are counted. */
    private const PATCHES_SHOWN = 6;

    /**
     * @param array{
     *     states?: ?array<string, array{state: string, released: ?string, active_until: ?string, security_until: ?string}>,
     *     active?: ?array<string, array{version: string, date: ?string, security: bool}>,
     *     atom?: ?list<array{title: string, published: string, link: string, text: string}>,
     *     tags?: ?list<array{version: string, branch: string, pre: ?string}>,
     *     endoflife?: ?array{generated_at: ?string, branches: array<string, array{released: ?string, active_until: ?string, security_until: ?string, latest: ?string, latest_date: ?string}>},
     *     wiki?: array<string, ?array{ga: ?string, rows: list<array{date: string, label: string, skipped: bool, kind: string, note: ?string, announce: ?string, links: list<array{label: string, url: string}>}>}>,
     *     wikiPages?: ?list<array{branch: string, page: string}>,
     *     master?: ?array{version: string, branch: string, major: int, minor: int, extra: string},
     *     frameworks?: array<string, array{name: string, url: string, majors: ?list<array{version: string, major: string, released: ?string, php: ?string}>, workflows?: array<string, array{lines: ?list<string>, url: string}>, lifecycle?: ?string, lifecycle_note?: ?string}>,
     *     platforms?: array<string, ?list<string>>,
     * } $in
     * @param list<array<string, mixed>> $sources
     *
     * @return array<string, mixed>
     */
    public static function build(array $in, DateTimeImmutable $now, array $sources = []): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $today = $now->format('Y-m-d');
        $states = $in['states'] ?? null;
        $active = $in['active'] ?? null;
        $atom = $in['atom'] ?? null;
        $tags = $in['tags'] ?? null;
        $eol = $in['endoflife'] ?? null;
        $eolBranches = $eol['branches'] ?? [];
        $notes = [];

        // Branch dates: php.net first, endoflife.date when php.net is missing.
        $branchSource = 'php.net';
        $dates = [];
        if ($states !== null) {
            foreach ($states as $branch => $s) {
                $dates[$branch] = $s;
            }
        } elseif ($eolBranches !== []) {
            $branchSource = 'endoflife.date';
            foreach ($eolBranches as $branch => $e) {
                $dates[$branch] = ['state' => 'unknown', 'released' => $e['released'], 'active_until' => $e['active_until'], 'security_until' => $e['security_until']];
            }
            $notes[] = 'php.net could not be read, so the branches and dates come from endoflife.date.';
        }

        $announced = self::announcedReleases($active, $atom);
        $finals = [];
        $pres = [];
        foreach ($tags ?? [] as $t) {
            if ($t['pre'] === null) {
                $finals[$t['branch']][] = $t['version'];
            } else {
                $pres[$t['branch']][] = $t;
            }
        }

        $branches = [];
        foreach ($dates as $branch => $d) {
            $state = self::stateOn($d, $today);
            if ($state === 'eol' && $d['security_until'] !== null && self::daysBetween($d['security_until'], $today) > self::RECENT_EOL_DAYS) {
                continue;
            }
            $latest = $announced[$branch] ?? null;
            $e = $eolBranches[$branch] ?? null;
            if ($e !== null && $e['latest'] !== null && ($latest === null || version_compare($e['latest'], $latest['version'], '>'))) {
                $latest = ['version' => $e['latest'], 'date' => $e['latest_date'], 'security' => false, 'source' => 'endoflife.date'];
            }
            if ($latest === null && isset($finals[$branch])) {
                $v = self::max($finals[$branch]);
                $latest = ['version' => $v, 'date' => null, 'security' => false, 'source' => 'php-src tag'];
            }

            $disagree = [];
            if ($branchSource === 'php.net' && $e !== null) {
                foreach (['released' => 'released', 'active_until' => 'active support ends', 'security_until' => 'security support ends'] as $field => $label) {
                    if ($d[$field] !== null && $e[$field] !== null && $d[$field] !== $e[$field]) {
                        $disagree[] = ['field' => $field, 'label' => $label, 'php_net' => $d[$field], 'endoflife' => $e[$field]];
                    }
                }
            }

            $branches[$branch] = [
                'branch' => $branch,
                'state' => $state,
                'released' => $d['released'],
                'active_until' => $d['active_until'],
                'security_until' => $d['security_until'],
                'days_left' => $state !== 'eol' && $d['security_until'] !== null ? self::daysBetween($today, $d['security_until']) : null,
                'is_new' => $d['released'] !== null && $d['released'] <= $today && self::daysBetween($d['released'], $today) <= self::NEW_DAYS,
                'latest' => $latest,
                'dates_from' => $branchSource,
                'disagreements' => $disagree,
                'next' => null,
            ];
        }
        uksort($branches, static fn(string $a, string $b): int => version_compare($a, $b));

        // What comes next, branch by branch: a final tag beats a release candidate.
        $upcoming = [];
        foreach ($branches as $branch => &$row) {
            $have = $row['latest']['version'] ?? null;
            $tagged = array_values(array_filter($finals[$branch] ?? [], static fn(string $v): bool => $have === null || version_compare($v, $have, '>')));
            if ($have !== null && $tagged !== []) {
                $row['next'] = ['version' => self::max($tagged), 'status' => 'tagged', 'evidence' => [self::evidence('php-src tag', 'Tagged in php-src, not announced on php.net')]];
            } elseif ($have !== null) {
                $rc = array_values(array_filter($pres[$branch] ?? [], static fn(array $t): bool => version_compare($t['version'], $have, '>')));
                if ($rc !== []) {
                    usort($rc, static fn(array $a, array $b): int => version_compare($b['version'] . '-' . $b['pre'], $a['version'] . '-' . $a['pre']));
                    $row['next'] = ['version' => $rc[0]['version'], 'status' => 'candidate', 'pre' => $rc[0]['pre'], 'evidence' => [self::evidence('php-src tag', $rc[0]['pre'] . ' tagged in php-src; release candidates are not releases')]];
                }
            }
        }
        unset($row);

        // php.net news notices.
        foreach (Parse::noticesFromAtom($atom ?? []) as $n) {
            $window = $n['end'] ?? ($n['day'] !== null ? $n['day'] . 'T23:59:59Z' : null);
            $newestRelease = self::newestAnnouncedDay($active, $atom);
            $satisfied = $n['day'] !== null
                ? ($newestRelease !== null && $newestRelease >= $n['day'])
                : self::releasedAfter($atom ?? [], $n['published']);
            if ($satisfied) {
                continue;
            }
            $expiry = $window !== null
                ? (new DateTimeImmutable($window))->modify('+' . self::NOTICE_GRACE_HOURS . ' hours')
                : (new DateTimeImmutable($n['published']))->modify('+3 days');
            if ($now > $expiry) {
                continue;
            }
            $applies = $n['branches'] ?? array_keys(array_filter($branches, static fn(array $b): bool => $b['state'] !== 'eol'));
            $status = $n['start'] !== null && $now >= new DateTimeImmutable($n['start']) ? 'due' : 'announced';
            $item = [
                'kind' => 'security',
                'status' => $status,
                'branches' => $applies,
                'start' => $n['start'],
                'end' => $n['end'],
                'day' => $n['day'],
                'title' => $n['title'],
                'evidence' => [self::evidence('php.net news', 'Announced on php.net news ' . substr($n['published'], 0, 10), $n['link'])],
            ];
            $upcoming[] = $item;
            foreach ($applies as $b) {
                if (!isset($branches[$b])) {
                    continue;
                }
                $next = $branches[$b]['next'];
                $evidence = array_merge($item['evidence'], $next['evidence'] ?? []);
                $branches[$b]['next'] = [
                    'version' => $next['version'] ?? null,
                    'status' => $status,
                    'start' => $n['start'],
                    'end' => $n['end'],
                    'day' => $n['day'],
                    'evidence' => $evidence,
                ];
            }
        }
        foreach ($branches as $b) {
            if ($b['next'] !== null && !in_array($b['next']['status'], ['announced', 'due'], true)) {
                $upcoming[] = ['kind' => 'patch', 'branches' => [$b['branch']]] + $b['next'];
            }
        }

        // A branch php-src has tagged that php.net does not list yet: the 8.6 night, a day early.
        $known = array_keys($dates);
        $futureTags = [];
        foreach (array_merge(array_keys($finals), array_keys($pres)) as $branch) {
            if (!in_array($branch, $known, true) && ($known === [] || version_compare($branch, self::max($known), '>'))) {
                $futureTags[$branch] = true;
            }
        }
        foreach (array_keys($futureTags) as $branch) {
            if (isset($finals[$branch])) {
                $upcoming[] = ['kind' => 'branch', 'branches' => [$branch], 'version' => self::max($finals[$branch]), 'status' => 'tagged',
                    'evidence' => [self::evidence('php-src tag', 'Tagged in php-src, not listed on php.net yet')]];
            } else {
                $newest = $pres[$branch];
                usort($newest, static fn(array $a, array $b): int => version_compare($b['version'] . '-' . $b['pre'], $a['version'] . '-' . $a['pre']));
                $upcoming[] = ['kind' => 'branch', 'branches' => [$branch], 'version' => $newest[0]['version'], 'status' => 'development', 'pre' => $newest[0]['pre'],
                    'evidence' => [self::evidence('php-src tag', $newest[0]['pre'] . ' tagged in php-src')]];
            }
        }

        // The wiki's timetable for a branch php.net does not list yet.
        $pages = self::pages($in['wikiPages'] ?? null);
        foreach ($in['wiki'] ?? [] as $branch => $w) {
            $branch = (string) $branch;
            if ($w === null || isset($branches[$branch])) {
                continue;
            }
            $ahead = array_values(array_filter($w['rows'], static fn(array $r): bool => !$r['skipped'] && $r['date'] >= $today));
            if ($w['ga'] !== null ? $w['ga'] < $today : $ahead === []) {
                continue;
            }
            $page = $pages[$branch] ?? self::pageUrl($branch);
            $label = $w['ga'] !== null ? 'GA on the wiki schedule' : 'Timetable on the wiki, no GA day yet';
            $step = $ahead === [] ? null : ['date' => $ahead[0]['date'], 'label' => $ahead[0]['label']];
            $found = false;
            foreach ($upcoming as &$u) {
                if ($u['kind'] === 'branch' && $u['branches'] === [$branch]) {
                    $u['day'] = $w['ga'];
                    $u['next_step'] = $step;
                    $u['evidence'][] = self::evidence('wiki schedule', $label, $page);
                    $found = true;
                }
            }
            unset($u);
            if (!$found) {
                $upcoming[] = ['kind' => 'branch', 'branches' => [$branch], 'version' => $branch . '.0', 'status' => 'scheduled', 'day' => $w['ga'], 'next_step' => $step,
                    'evidence' => [self::evidence('wiki schedule', $label, $page)]];
            }
        }

        // The lines still to come, from php-src master: the version master will become, and
        // the major after it. One with nothing published says so; nothing is estimated.
        foreach (self::futureLines($in['master'] ?? null) as $line) {
            if (isset($branches[$line['branch']]) || ($in['wiki'][$line['branch']] ?? null) !== null) {
                continue;
            }
            $covered = array_filter($upcoming, static fn(array $u): bool => in_array($line['branch'], $u['branches'], true));
            if ($covered !== []) {
                continue;
            }
            $evidence = [
                self::evidence('php-src master', 'php-src master is ' . $line['master'], self::MASTER_LINK),
                self::evidence('wiki timetables', 'No timetable page in the wiki index', self::INDEX_LINK),
            ];
            if ($line['role'] === 'major') {
                $evidence[] = self::evidence('RFCs', 'RFCs name ' . $line['branch'] . ' as a target; no date', self::RFC_LINK);
            }
            $upcoming[] = ['kind' => 'branch', 'branches' => [$line['branch']], 'version' => $line['branch'] . '.0', 'status' => 'no-timeline', 'role' => $line['role'],
                'day' => null, 'checked_at' => self::checkedAt($sources, ['master', 'wiki-index']), 'evidence' => $evidence];
        }
        usort($upcoming, static function (array $a, array $b): int {
            $da = $a['start'] ?? ($a['day'] ?? '9999');
            $db = $b['start'] ?? ($b['day'] ?? '9999');

            return [$da, self::rank($a['status'])] <=> [$db, self::rank($b['status'])];
        });

        // Framework fit: what each framework's newest majors declare in require.php (allows), what
        // that major's own CI runs (tested), and the two together (fit). A constraint like ^8.3
        // "allows" every 8.x to come; only the CI matrix says the framework has tried it.
        $frameworks = [];
        foreach ($in['frameworks'] ?? [] as $package => $f) {
            foreach ($f['majors'] ?? [] as $m) {
                $ci = $f['workflows'][$m['major']] ?? null;
                $lines = $ci['lines'] ?? null;
                $allows = [];
                $tested = $lines === null ? null : [];
                $fit = [];
                foreach (array_keys($branches) as $branch) {
                    $allows[$branch] = $m['php'] === null ? null : Constraint::allowsBranch($m['php'], $branch);
                    if ($tested !== null) {
                        $tested[$branch] = in_array($branch, $lines ?? [], true);
                    }
                    $fit[$branch] = self::fit($allows[$branch], $tested === null ? null : $tested[$branch]);
                }
                $frameworks[] = ['package' => $package, 'name' => $f['name'], 'url' => $f['url'], 'major' => $m['major'], 'version' => $m['version'], 'released' => $m['released'], 'php' => $m['php'], 'allows' => $allows,
                    'tested' => $tested, 'tested_lines' => $lines, 'tested_source' => $ci['url'] ?? null, 'fit' => $fit,
                    'lifecycle' => $f['lifecycle'] ?? null, 'lifecycle_note' => $f['lifecycle_note'] ?? null];
            }
        }

        // endoflife.date behind php.net is worth saying out loud.
        if ($states !== null && $eolBranches !== []) {
            $missing = array_values(array_filter(array_keys($branches), static fn(string $b): bool => !isset($eolBranches[$b]) && $branches[$b]['state'] !== 'eol'));
            if ($missing !== []) {
                $notes[] = 'endoflife.date does not list ' . implode(', ', $missing) . ' yet; php.net does.';
            }
        }

        // Platform rows (stub): what a hosting platform offers today, platform name => PHP branches it
        // ships. No source feeds this yet: Citadel keeps image status in its dashboard database and its
        // images file needs a token (research/citadel-images-php2shell-1119.md). A source that lists
        // only images that are built goes in Feed::plan() and fills this; planned images never count.
        $platforms = [];
        foreach ($in['platforms'] ?? [] as $name => $offered) {
            if ($offered !== null) {
                $platforms[] = ['name' => (string) $name, 'branches' => array_values(array_intersect($offered, array_keys($branches)))];
            }
        }

        $released = array_filter($branches, static fn(array $b): bool => $b['released'] !== null && $b['released'] <= $today);
        $timelines = self::timelines($in, $dates, $branchSource, $branches, $upcoming, $finals, $today);

        return [
            'schema' => self::SCHEMA,
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'today' => $today,
            'newest' => $released === [] ? null : (string) array_key_last($released),
            'branches' => array_values($branches),
            'upcoming' => $upcoming,
            'timelines' => $timelines,
            'frameworks' => $frameworks,
            'platforms' => $platforms,
            'sources' => $sources,
            'notes' => $notes,
        ];
    }

    /**
     * One timeline per release, newest first: every branch php.net lists, every page in the
     * wiki's index, and every line still to come. Each joins the wiki's milestones (alpha to
     * GA, skipped steps kept and marked) with php.net's lifecycle dates (GA, end of active
     * support, end of security support) and the patch releases that already happened. A
     * future patch date appears only when php.net news published one.
     *
     * @param array<string, mixed> $in
     * @param array<string, array{state: string, released: ?string, active_until: ?string, security_until: ?string}> $dates
     * @param array<string, array<string, mixed>> $branches
     * @param list<array<string, mixed>> $upcoming
     * @param array<string, list<string>> $finals
     *
     * @return list<array<string, mixed>>
     */
    private static function timelines(array $in, array $dates, string $branchSource, array $branches, array $upcoming, array $finals, string $today): array
    {
        /** @var array<string, ?array{ga: ?string, rows: list<array{date: string, label: string, skipped: bool, kind: string, note: ?string, announce: ?string, links: list<array{label: string, url: string}>}>}> $wiki */
        $wiki = is_array($in['wiki'] ?? null) ? $in['wiki'] : [];
        $pages = self::pages($in['wikiPages'] ?? null);
        /** @var ?array<string, array{version: string, date: ?string, security: bool}> $active */
        $active = $in['active'] ?? null;
        /** @var ?list<array{title: string, published: string, link: string, text: string}> $atom */
        $atom = $in['atom'] ?? null;

        $future = [];
        foreach ($upcoming as $u) {
            if (($u['kind'] ?? null) === 'branch' && is_array($u['branches'] ?? null) && count($u['branches']) === 1) {
                $future[(string) $u['branches'][0]] = $u;
            }
        }
        $all = array_unique(array_merge(array_map('strval', array_keys($dates)), array_keys($pages), array_keys($future)));
        usort($all, static fn(string $a, string $b): int => version_compare($b, $a));

        // Patch releases with a day on them: php.net's releases list and its news feed.
        $dated = [];
        foreach ($active ?? [] as $branch => $a) {
            $dated[(string) $branch][$a['version']] = ['version' => $a['version'], 'date' => $a['date'], 'security' => $a['security'], 'source' => 'php.net releases'];
        }
        foreach (Parse::releasesFromAtom($atom ?? []) as $r) {
            $dated[Parse::branchOf($r['version'])][$r['version']] ??= ['version' => $r['version'], 'date' => $r['date'], 'security' => $r['security'], 'source' => 'php.net news'];
        }

        $out = [];
        foreach ($all as $branch) {
            $d = $dates[$branch] ?? null;
            $w = $wiki[$branch] ?? null;
            $state = $d !== null ? self::stateOn($d, $today) : (isset($future[$branch]) || isset($pages[$branch]) && self::isAfter($branch, array_keys($dates)) ? 'future' : 'unknown');
            if ($state === 'unknown' && !isset($pages[$branch])) {
                continue;
            }

            $steps = [];
            foreach ($w['rows'] ?? [] as $r) {
                $steps[] = $r + ['source' => 'wiki', 'done' => !$r['skipped'] && $r['date'] <= $today];
            }
            if ($d !== null) {
                $wikiGa = $w['ga'] ?? null;
                if ($d['released'] !== null && $wikiGa !== $d['released']) {
                    $steps[] = self::step($d['released'], 'General availability', 'ga', $today, $wikiGa === null ? null : 'php.net gives this day; the wiki said ' . $wikiGa);
                }
                if ($d['active_until'] !== null) {
                    $steps[] = self::step($d['active_until'], 'Active support ends', 'active-end', $today, 'Bug fixes stop; security fixes go on.');
                }
                if ($d['security_until'] !== null) {
                    $steps[] = self::step($d['security_until'], 'Security support ends', 'security-end', $today, 'End of life: no more fixes of any kind.');
                }
            }
            usort($steps, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

            // x.y.0 is the GA, already on the timeline; patches start at x.y.1.
            $patches = array_values(array_filter($dated[$branch] ?? [], static fn(array $p): bool => !str_ends_with($p['version'], '.0')));
            usort($patches, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
            $tagged = array_values(array_filter($finals[$branch] ?? [], static fn(string $v): bool => !str_ends_with($v, '.0')));
            usort($tagged, static fn(string $a, string $b): int => version_compare($a, $b));

            $next = $branches[$branch]['next'] ?? null;
            $nextPatch = is_array($next) && in_array($next['status'] ?? null, ['announced', 'due'], true) && ($next['day'] ?? null) !== null
                ? ['version' => $next['version'] ?? null, 'day' => $next['day'], 'start' => $next['start'] ?? null, 'end' => $next['end'] ?? null, 'status' => $next['status'], 'evidence' => $next['evidence'] ?? []]
                : null;

            $out[] = [
                'branch' => $branch,
                'state' => $state,
                'page' => $pages[$branch] ?? null,
                'timetable' => $w !== null ? 'published' : (isset($pages[$branch]) ? (array_key_exists($branch, $wiki) ? 'no-dates' : 'not-read') : 'none'),
                'released' => $d['released'] ?? null,
                'active_until' => $d['active_until'] ?? null,
                'security_until' => $d['security_until'] ?? null,
                'dates_from' => $d !== null ? $branchSource : null,
                'steps' => $steps,
                'patches' => [
                    'dated' => array_slice($patches, 0, self::PATCHES_SHOWN),
                    'tagged' => count($tagged),
                    'first' => $tagged[0] ?? null,
                    'last' => $tagged === [] ? null : $tagged[count($tagged) - 1],
                    'next' => $nextPatch,
                ],
                'upcoming' => $future[$branch] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array{date: string, label: string, skipped: bool, kind: string, note: ?string, announce: ?string, links: list<array{label: string, url: string}>, source: string, done: bool}
     */
    private static function step(string $date, string $label, string $kind, string $today, ?string $note): array
    {
        return ['date' => $date, 'label' => $label, 'skipped' => false, 'kind' => $kind, 'note' => $note, 'announce' => null, 'links' => [], 'source' => 'php.net', 'done' => $date <= $today];
    }

    /**
     * @param list<int|string> $known
     */
    private static function isAfter(string $branch, array $known): bool
    {
        if ($known === []) {
            return false;
        }
        foreach ($known as $k) {
            if (version_compare($branch, (string) $k, '<=')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The wiki index as branch => page URL.
     *
     * @return array<string, string>
     */
    private static function pages(mixed $index): array
    {
        $out = [];
        foreach (is_array($index) ? $index : [] as $p) {
            if (is_array($p) && is_string($p['branch'] ?? null) && is_string($p['page'] ?? null)) {
                $out[$p['branch']] = 'https://wiki.php.net/todo/' . $p['page'];
            }
        }

        return $out;
    }

    public static function pageUrl(string $branch): string
    {
        return 'https://wiki.php.net/todo/php' . str_replace('.', '', $branch);
    }

    /**
     * php-src master's version, and the major after it unless master already is a new major.
     *
     * @return list<array{branch: string, role: string, master: string}>
     */
    private static function futureLines(mixed $master): array
    {
        if (!is_array($master) || !is_int($master['major'] ?? null) || !is_int($master['minor'] ?? null) || !is_string($master['version'] ?? null)) {
            return [];
        }
        $lines = [['branch' => $master['major'] . '.' . $master['minor'], 'role' => 'development', 'master' => $master['version']]];
        if ($master['minor'] !== 0) {
            $lines[] = ['branch' => ($master['major'] + 1) . '.0', 'role' => 'major', 'master' => $master['version']];
        }

        return $lines;
    }

    /**
     * The oldest good read among the named sources: "checked" means all of them were.
     *
     * @param list<array<string, mixed>> $sources
     * @param list<string> $ids
     */
    private static function checkedAt(array $sources, array $ids): ?string
    {
        $at = [];
        foreach ($sources as $s) {
            if (in_array($s['id'] ?? null, $ids, true)) {
                if (!is_string($s['ok_at'] ?? null)) {
                    return null;
                }
                $at[] = $s['ok_at'];
            }
        }

        return $at === [] ? null : min($at);
    }

    /**
     * The newest announced release per branch: active.php, or a newer "Released!" entry on the news feed.
     *
     * @param ?array<string, array{version: string, date: ?string, security: bool}> $active
     * @param ?list<array{title: string, published: string, link: string, text: string}> $atom
     *
     * @return array<string, array{version: string, date: ?string, security: bool, source: string}>
     */
    private static function announcedReleases(?array $active, ?array $atom): array
    {
        $out = [];
        foreach ($active ?? [] as $branch => $a) {
            $out[$branch] = $a + ['source' => 'php.net releases'];
        }
        foreach (Parse::releasesFromAtom($atom ?? []) as $r) {
            $branch = Parse::branchOf($r['version']);
            if (!isset($out[$branch]) || version_compare($r['version'], $out[$branch]['version'], '>')) {
                $out[$branch] = ['version' => $r['version'], 'date' => $r['date'], 'security' => $r['security'], 'source' => 'php.net news'];
            }
        }

        return $out;
    }

    /**
     * @param ?array<string, array{version: string, date: ?string, security: bool}> $active
     * @param ?list<array{title: string, published: string, link: string, text: string}> $atom
     */
    private static function newestAnnouncedDay(?array $active, ?array $atom): ?string
    {
        $days = [];
        foreach ($active ?? [] as $a) {
            if ($a['date'] !== null) {
                $days[] = $a['date'];
            }
        }
        foreach (Parse::releasesFromAtom($atom ?? []) as $r) {
            $days[] = $r['date'];
        }

        return $days === [] ? null : max($days);
    }

    /**
     * @param list<array{title: string, published: string, link: string, text: string}> $atom
     */
    private static function releasedAfter(array $atom, string $instant): bool
    {
        foreach ($atom as $e) {
            if ($e['published'] > $instant && preg_match('/^PHP\s+\d+\.\d+\.\d+\s+(?:is\s+)?released/i', $e['title'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{state: string, released: ?string, active_until: ?string, security_until: ?string} $d
     */
    private static function stateOn(array $d, string $today): string
    {
        if ($d['released'] !== null && $d['released'] > $today) {
            return 'planned';
        }
        if ($d['security_until'] !== null && $today > $d['security_until']) {
            return 'eol';
        }
        if ($d['active_until'] !== null && $today > $d['active_until']) {
            return 'security';
        }
        if ($d['active_until'] !== null) {
            return 'active';
        }

        return match ($d['state']) {
            'stable' => 'active',
            'security' => 'security',
            'eol' => 'eol',
            default => 'unknown',
        };
    }

    private static function daysBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable($from . 'T00:00:00Z');
        $b = new DateTimeImmutable($to . 'T00:00:00Z');

        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /**
     * @param list<string> $versions
     */
    private static function max(array $versions): string
    {
        usort($versions, static fn(string $a, string $b): int => version_compare($b, $a));

        return $versions[0];
    }

    /**
     * @return array{source: string, label: string, url: ?string}
     */
    private static function evidence(string $source, string $label, ?string $url = null): array
    {
        return ['source' => $source, 'label' => $label, 'url' => $url];
    }

    /**
     * One framework cell: "tested" (require.php allows the line and the major's CI runs it),
     * "untested" (Composer would install it; the CI does not run it yet), "allowed" (allowed,
     * and the CI workflow was not read, so nothing is claimed), "no" (require.php refuses it),
     * or null (no constraint to read). A CI run on a line the constraint refuses is still "no":
     * Slim 4 runs 8.6 with --ignore-platform-req, Composer would not install it.
     */
    private static function fit(?bool $allows, ?bool $tested): ?string
    {
        return match (true) {
            $allows === null => null,
            $allows === false => 'no',
            $tested === null => 'allowed',
            $tested => 'tested',
            default => 'untested',
        };
    }

    private static function rank(string $status): int
    {
        return match ($status) {
            'due' => 0,
            'announced' => 1,
            'tagged' => 2,
            'scheduled' => 3,
            'candidate' => 4,
            'development' => 5,
            default => 6,
        };
    }
}
