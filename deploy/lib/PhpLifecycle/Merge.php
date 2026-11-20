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
 *    schedule".
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

    /**
     * @param array{
     *     states?: ?array<string, array{state: string, released: ?string, active_until: ?string, security_until: ?string}>,
     *     active?: ?array<string, array{version: string, date: ?string, security: bool}>,
     *     atom?: ?list<array{title: string, published: string, link: string, text: string}>,
     *     tags?: ?list<array{version: string, branch: string, pre: ?string}>,
     *     endoflife?: ?array{generated_at: ?string, branches: array<string, array{released: ?string, active_until: ?string, security_until: ?string, latest: ?string, latest_date: ?string}>},
     *     wiki?: array<string, ?array{ga: ?string, rows: list<array{date: string, label: string, skipped: bool}>}>,
     *     frameworks?: array<string, array{name: string, url: string, majors: ?list<array{version: string, major: string, released: ?string, php: ?string}>}>,
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
        uksort($branches, static fn (string $a, string $b): int => version_compare($a, $b));

        // What comes next, branch by branch: a final tag beats a release candidate.
        $upcoming = [];
        foreach ($branches as $branch => &$row) {
            $have = $row['latest']['version'] ?? null;
            $tagged = array_values(array_filter($finals[$branch] ?? [], static fn (string $v): bool => $have === null || version_compare($v, $have, '>')));
            if ($have !== null && $tagged !== []) {
                $row['next'] = ['version' => self::max($tagged), 'status' => 'tagged', 'evidence' => [self::evidence('php-src tag', 'Tagged in php-src, not announced on php.net')]];
            } elseif ($have !== null) {
                $rc = array_values(array_filter($pres[$branch] ?? [], static fn (array $t): bool => version_compare($t['version'], $have, '>')));
                if ($rc !== []) {
                    usort($rc, static fn (array $a, array $b): int => version_compare($b['version'] . '-' . $b['pre'], $a['version'] . '-' . $a['pre']));
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
            $applies = $n['branches'] ?? array_keys(array_filter($branches, static fn (array $b): bool => $b['state'] !== 'eol'));
            $status = $n['start'] !== null && $now >= new DateTimeImmutable($n['start']) ? 'due' : 'announced';
            $item = [
                'kind' => 'security',
                'status' => $status,
                'branches' => array_values($applies),
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
                usort($newest, static fn (array $a, array $b): int => version_compare($b['version'] . '-' . $b['pre'], $a['version'] . '-' . $a['pre']));
                $upcoming[] = ['kind' => 'branch', 'branches' => [$branch], 'version' => $newest[0]['version'], 'status' => 'development', 'pre' => $newest[0]['pre'],
                    'evidence' => [self::evidence('php-src tag', $newest[0]['pre'] . ' tagged in php-src')]];
            }
        }

        // The wiki's timetable for a branch php.net does not list yet.
        foreach ($in['wiki'] ?? [] as $branch => $w) {
            if ($w === null || $w['ga'] === null || isset($branches[$branch]) || $w['ga'] < $today) {
                continue;
            }
            $found = false;
            foreach ($upcoming as &$u) {
                if (($u['kind'] ?? '') === 'branch' && $u['branches'] === [$branch]) {
                    $u['day'] = $w['ga'];
                    $u['evidence'][] = self::evidence('wiki schedule', 'GA on the wiki schedule', 'https://wiki.php.net/todo/php' . str_replace('.', '', $branch));
                    $found = true;
                }
            }
            unset($u);
            if (!$found) {
                $upcoming[] = ['kind' => 'branch', 'branches' => [$branch], 'version' => $branch . '.0', 'status' => 'scheduled', 'day' => $w['ga'],
                    'evidence' => [self::evidence('wiki schedule', 'GA on the wiki schedule', 'https://wiki.php.net/todo/php' . str_replace('.', '', $branch))]];
            }
        }
        usort($upcoming, static function (array $a, array $b): int {
            $da = $a['start'] ?? ($a['day'] ?? '9999');
            $db = $b['start'] ?? ($b['day'] ?? '9999');

            return [$da, self::rank($a['status'] ?? '')] <=> [$db, self::rank($b['status'] ?? '')];
        });

        // Framework fit: what each framework's newest majors declare in require.php.
        $frameworks = [];
        foreach ($in['frameworks'] ?? [] as $package => $f) {
            foreach ($f['majors'] ?? [] as $m) {
                $allows = [];
                foreach (array_keys($branches) as $branch) {
                    $allows[$branch] = $m['php'] === null ? null : Constraint::allowsBranch($m['php'], $branch);
                }
                $frameworks[] = ['package' => $package, 'name' => $f['name'], 'url' => $f['url'], 'major' => $m['major'], 'version' => $m['version'], 'released' => $m['released'], 'php' => $m['php'], 'allows' => $allows];
            }
        }

        // endoflife.date behind php.net is worth saying out loud.
        if ($states !== null && $eolBranches !== []) {
            $missing = array_values(array_filter(array_keys($branches), static fn (string $b): bool => !isset($eolBranches[$b]) && $branches[$b]['state'] !== 'eol'));
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

        $released = array_filter($branches, static fn (array $b): bool => $b['released'] !== null && $b['released'] <= $today);

        return [
            'schema' => self::SCHEMA,
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'today' => $today,
            'newest' => $released === [] ? null : (string) array_key_last($released),
            'branches' => array_values($branches),
            'upcoming' => $upcoming,
            'frameworks' => $frameworks,
            'platforms' => $platforms,
            'sources' => $sources,
            'notes' => $notes,
        ];
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
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        return $versions[0];
    }

    /**
     * @return array{source: string, label: string, url: ?string}
     */
    private static function evidence(string $source, string $label, ?string $url = null): array
    {
        return ['source' => $source, 'label' => $label, 'url' => $url];
    }

    private static function rank(string $status): int
    {
        return match ($status) {
            'due' => 0,
            'announced' => 1,
            'tagged' => 2,
            'scheduled' => 3,
            'candidate' => 4,
            default => 5,
        };
    }
}
