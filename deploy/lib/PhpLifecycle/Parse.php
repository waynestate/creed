<?php

declare(strict_types=1);

namespace Waynestate\Creed\Site\PhpLifecycle;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One parser per source. Each takes the raw body exactly as the source sent
 * it and returns plain arrays, or null when the body is not what that source
 * sends (an HTML error page, a truncated download). Pure functions: no
 * network, no clock. Every value here came out of a body; none is typed in.
 */
final class Parse
{
    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

    /**
     * php.net releases/states.php: every branch with its state and dates.
     *
     * @return array<string, array{state: string, released: ?string, active_until: ?string, security_until: ?string}>|null
     */
    public static function states(string $body): ?array
    {
        $data = self::json($body);
        if ($data === null) {
            return null;
        }
        $out = [];
        foreach ($data as $major) {
            if (!is_array($major)) {
                continue;
            }
            foreach ($major as $branch => $info) {
                if (!is_array($info) || !preg_match('/^\d+\.\d+$/', (string) $branch)) {
                    continue;
                }
                $out[(string) $branch] = [
                    'state' => is_string($info['state'] ?? null) ? $info['state'] : 'unknown',
                    'released' => self::day($info['initial_release'] ?? null),
                    'active_until' => self::day($info['active_support_end'] ?? null),
                    'security_until' => self::day($info['security_support_end'] ?? null),
                ];
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * php.net releases/active.php: the newest release of each supported branch.
     *
     * @return array<string, array{version: string, date: ?string, security: bool}>|null
     */
    public static function active(string $body): ?array
    {
        $data = self::json($body);
        if ($data === null) {
            return null;
        }
        $out = [];
        foreach ($data as $major) {
            if (!is_array($major)) {
                continue;
            }
            foreach ($major as $branch => $info) {
                if (!is_array($info) || !is_string($info['version'] ?? null)) {
                    continue;
                }
                $version = $info['version'];
                if (!self::isFinal($version) || self::branchOf($version) !== (string) $branch) {
                    continue;
                }
                $tags = is_array($info['tags'] ?? null) ? $info['tags'] : [];
                $out[(string) $branch] = [
                    'version' => $version,
                    'date' => self::day($info['date'] ?? null),
                    'security' => in_array('security', $tags, true),
                ];
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * php.net feed.atom: the entries, newest first, as plain text.
     *
     * @return list<array{title: string, published: string, link: string, text: string}>|null
     */
    public static function atom(string $body): ?array
    {
        if (!str_contains($body, '<feed')) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return null;
        }
        $out = [];
        foreach ($xml->children('http://www.w3.org/2005/Atom')->entry as $entry) {
            $published = self::instant((string) ($entry->published ?: $entry->updated));
            if ($published === null) {
                continue;
            }
            $link = '';
            foreach ($entry->link as $l) {
                $attrs = $l->attributes();
                if ($attrs !== null && ((string) $attrs['rel'] === 'alternate' || $link === '')) {
                    $link = (string) $attrs['href'];
                }
            }
            // The content is XHTML in its own namespace; the DOM's text is the words without the markup.
            $node = dom_import_simplexml($entry->content);
            $text = html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $out[] = [
                'title' => trim((string) $entry->title),
                'published' => $published,
                'link' => $link,
                'text' => trim((string) preg_replace('/\s+/u', ' ', $text)),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($b['published'], $a['published']));

        return $out;
    }

    /**
     * "PHP 8.5.13 Released!" entries: version and the day they were posted.
     *
     * @param list<array{title: string, published: string, link: string, text: string}> $entries
     *
     * @return list<array{version: string, date: string, security: bool, link: string}>
     */
    public static function releasesFromAtom(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (preg_match('/^PHP\s+(\d+\.\d+\.\d+)\s+(?:is\s+)?released/i', $e['title'], $m)) {
                $out[] = [
                    'version' => $m[1],
                    'date' => substr($e['published'], 0, 10),
                    'security' => (bool) preg_match('/\bsecurity (?:fix )?release\b/i', $e['text']),
                    'link' => $e['link'],
                ];
            }
        }

        return $out;
    }

    /**
     * "Upcoming ... Release" notices, with the schedule read out of the text.
     * The text is free-form, so a date that cannot be read stays null.
     *
     * @param list<array{title: string, published: string, link: string, text: string}> $entries
     *
     * @return list<array{title: string, published: string, link: string, start: ?string, end: ?string, day: ?string, branches: ?list<string>}>
     */
    public static function noticesFromAtom(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (!preg_match('/\bupcoming\b.*\brelease/i', $e['title'])) {
                continue;
            }
            $day = self::dayInText($e['text']);
            $start = null;
            $end = null;
            if ($day !== null) {
                if (preg_match('/(\d{1,2}:\d{2})\s*(?:and|to|-|\x{2013})\s*(\d{1,2}:\d{2})\s*UTC/iu', $e['text'], $t)) {
                    $start = self::instant($day . 'T' . $t[1] . ':00+00:00');
                    $end = self::instant($day . 'T' . $t[2] . ':00+00:00');
                } elseif (preg_match('/(\d{1,2}:\d{2})\s*UTC/i', $e['text'], $t)) {
                    $start = self::instant($day . 'T' . $t[1] . ':00+00:00');
                    $end = $start;
                }
            }
            $branches = null;
            if (!preg_match('/\ball supported\b/i', $e['text'])
                && preg_match_all('/\bPHP\s+(\d+\.\d+)(?![.\d])/', $e['text'], $b)) {
                $branches = array_values(array_unique($b[1]));
            }
            $out[] = [
                'title' => $e['title'],
                'published' => $e['published'],
                'link' => $e['link'],
                'start' => $start,
                'end' => $end,
                'day' => $day,
                'branches' => $branches,
            ];
        }

        return $out;
    }

    /**
     * GitHub git/matching-refs/tags/php-N.: tag names as parsed versions.
     *
     * @return list<array{version: string, branch: string, pre: ?string}>|null
     */
    public static function tags(string $body): ?array
    {
        $data = self::json($body);
        if ($data === null || !array_is_list($data)) {
            return null;
        }
        $out = [];
        foreach ($data as $ref) {
            $name = is_array($ref) && is_string($ref['ref'] ?? null) ? $ref['ref'] : (is_array($ref) && is_string($ref['name'] ?? null) ? $ref['name'] : null);
            if ($name === null) {
                continue;
            }
            if (preg_match('#(?:^|/)php-(\d+\.\d+\.\d+)((?:alpha|beta|RC)\d+)?$#i', $name, $m)) {
                $out[] = [
                    'version' => $m[1],
                    'branch' => self::branchOf($m[1]),
                    'pre' => $m[2] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * endoflife.date api/v1/products/php.
     *
     * @return array{generated_at: ?string, branches: array<string, array{released: ?string, active_until: ?string, security_until: ?string, latest: ?string, latest_date: ?string}>}|null
     */
    public static function endoflife(string $body): ?array
    {
        $data = self::json($body);
        $releases = $data['result']['releases'] ?? null;
        if (!is_array($releases)) {
            return null;
        }
        $out = [];
        foreach ($releases as $r) {
            if (!is_array($r) || !is_string($r['name'] ?? null) || !preg_match('/^\d+\.\d+$/', $r['name'])) {
                continue;
            }
            $latest = is_array($r['latest'] ?? null) ? $r['latest'] : [];
            $out[$r['name']] = [
                'released' => self::day($r['releaseDate'] ?? null),
                'active_until' => self::day($r['eoasFrom'] ?? null),
                'security_until' => self::day($r['eolFrom'] ?? null),
                'latest' => is_string($latest['name'] ?? null) && self::isFinal($latest['name']) ? $latest['name'] : null,
                'latest_date' => self::day($latest['date'] ?? null),
            ];
        }

        return $out === [] ? null : [
            'generated_at' => self::instant(is_string($data['generated_at'] ?? null) ? $data['generated_at'] : ''),
            'branches' => $out,
        ];
    }

    /**
     * php-src master's main/php_version.h: the version the development line will become.
     *
     * @return array{version: string, branch: string, major: int, minor: int, extra: string}|null
     */
    public static function phpVersionH(string $body): ?array
    {
        if (!preg_match('/^\s*#\s*define\s+PHP_VERSION\s+"(\d+)\.(\d+)\.(\d+)([^"]*)"/m', $body, $m)) {
            return null;
        }

        return [
            'version' => $m[1] . '.' . $m[2] . '.' . $m[3] . $m[4],
            'branch' => $m[1] . '.' . $m[2],
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'extra' => $m[4],
        ];
    }

    /**
     * wiki.php.net todo?do=export_raw: the index of release pages, one per branch.
     * A struck-through entry (PHP 6, which never shipped) is not a release. Sub-pages
     * such as PHP531 are not branches. Majors 10 to 19 read as PHP10x.
     *
     * @return list<array{branch: string, page: string}>|null
     */
    public static function wikiIndex(string $body): ?array
    {
        if (self::isHtml($body) || !str_contains($body, '[[')) {
            return null;
        }
        $out = [];
        $body = (string) preg_replace('#<del>.*?</del>#is', '', $body);
        if (preg_match_all('/\[\[\s*todo:(php)(1\d|\d)(\d)\s*(?:\|[^\]]*)?\]\]/i', $body, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $branch = $m[2] . '.' . $m[3];
                $out[$branch] = ['branch' => $branch, 'page' => strtolower($m[1] . $m[2] . $m[3])];
            }
        }
        uksort($out, static fn(string $a, string $b): int => version_compare($b, $a));

        return array_values($out);
    }

    /**
     * wiki.php.net todo/phpNN?do=export_raw: the release timetable, as the page has it.
     *
     * Two layouts exist. From 5.5 on, a table: `| Jul 02 2026 | Alpha 1 [[link|Announce]] |`,
     * with skipped steps struck through (`<del>RC1</del>`) or described as skipped, and
     * notes rows ("Feature freeze") between the releases. Before that, a list under
     * "Timetable": `- alpha 1 - June 28 2011 **DONE**`. A list item without a year is left out.
     *
     * @return array{ga: ?string, rows: list<array{date: string, label: string, skipped: bool, kind: string, note: ?string, announce: ?string, links: list<array{label: string, url: string}>}>}|null
     */
    public static function wiki(string $body): ?array
    {
        if (self::isHtml($body)) {
            return null;
        }
        $rows = [];
        $date = '[A-Z][a-z]{2,8}\.?\s+\d{1,2},?\s+\d{4}';
        if (preg_match_all('#^\|\s*(<del>\s*)?(' . $date . ')\s*(?:</del>\s*)?\|(.*)$#m', $body, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $day = self::looseDay($m[2]);
                if ($day !== null) {
                    $rows[] = self::wikiRow($day, $m[3], $m[1] !== '');
                }
            }
        }
        if ($rows === [] && preg_match('/^=+\s*Timetable\s*=+\s*$(.*?)(?=^=+|\z)/ims', $body, $section)) {
            $months = self::MONTHS . '|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';
            foreach (preg_split('/\R/', $section[1]) ?: [] as $line) {
                if (!preg_match('/^\s+[-*]\s+(.*)$/', $line, $item)
                    || !preg_match("/\\b({$months})\\.?\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s+(\\d{4})\\b/i", $item[1], $d)) {
                    continue;
                }
                $day = self::looseDay(ucfirst(strtolower(substr($d[1], 0, 3))) . ' ' . $d[2] . ' ' . $d[3]);
                if ($day === null) {
                    continue;
                }
                $text = str_replace($d[0], '', $item[1]);
                $text = (string) preg_replace('/\*\*\s*(DONE|COMPLETED)\s*\*\*/i', '', $text);
                $text = (string) preg_replace('/\s+on\s*$|\s+-\s+|\s+-\s*$/', ' ', $text);
                $rows[] = self::wikiRow($day, $text, false);
            }
        }
        if ($rows === []) {
            return null;
        }
        // Page order is date order with ties kept as written (RC1 skipped, then RC2 the same day).
        usort($rows, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
        $ga = null;
        foreach ($rows as $r) {
            if ($r['kind'] === 'ga' && !$r['skipped']) {
                $ga = $r['date'];
                break;
            }
        }

        return ['ga' => $ga, 'rows' => $rows];
    }

    /**
     * One timetable row: the label as written, its links, whether it was skipped, and what kind of step it is.
     *
     * @return array{date: string, label: string, skipped: bool, kind: string, note: ?string, announce: ?string, links: list<array{label: string, url: string}>}
     */
    private static function wikiRow(string $day, string $cell, bool $dateStruck): array
    {
        $links = [];
        $announce = null;
        // [[url|Label]] links: Announce and Files are links, not words; any other link keeps its words.
        $text = (string) preg_replace_callback('/\[\[\s*([^|\]]+?)\s*(?:\|([^\]]*))?\]\]/', static function (array $m) use (&$links, &$announce): string {
            $url = trim($m[1]);
            $label = trim($m[2] ?? '');
            if (!preg_match('#^https?://#i', $url)) {
                return $label;
            }
            if (strcasecmp($label, 'Announce') === 0) {
                $announce ??= $url;

                return '';
            }
            if (strcasecmp($label, 'Files') === 0) {
                return '';
            }
            $links[] = ['label' => $label === '' ? $url : $label, 'url' => $url];

            return $label;
        }, $cell);
        $bare = (string) preg_replace('/\[\[[^\]]*\]\]/', '', $cell);
        $skipped = $dateStruck || str_contains($cell, '<del>') || (bool) preg_match('/\bskipped\b/i', $bare);

        $cells = array_values(array_filter(array_map(
            static fn(string $c): string => trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace(['**', '//'], '', $c)))),
            explode('|', $text),
        ), static fn(string $c): bool => $c !== ''));
        $label = $cells[0] ?? '';
        $note = count($cells) > 1 ? implode('; ', array_slice($cells, 1)) : null;
        // "RC7 (extra unplanned RC release, see explanation)": the step, then why.
        if (preg_match('/^(.+?)\s*\((.+)\)\s*$/', $label, $p)) {
            $label = $p[1];
            $note = $note === null ? $p[2] : $p[2] . '; ' . $note;
        }

        return [
            'date' => $day,
            'label' => $label,
            'skipped' => $skipped,
            'kind' => self::milestoneKind($label),
            'note' => $note,
            'announce' => $announce,
            'links' => $links,
        ];
    }

    private static function milestoneKind(string $label): string
    {
        return match (true) {
            (bool) preg_match('/^(GA|General availability|Final)\b/i', $label),
            (bool) preg_match('/^\d+\.\d+\.0\s+(?:-\s*)?released\b/i', $label) => 'ga',
            (bool) preg_match('/^\d+\.\d+\.\d+/', $label) => 'patch',
            (bool) preg_match('/^alpha/i', $label) => 'alpha',
            (bool) preg_match('/^beta/i', $label) => 'beta',
            (bool) preg_match('/^RC\b|^RC\s?\d/i', $label) => 'rc',
            (bool) preg_match('/feature freeze|branched/i', $label) => 'freeze',
            default => 'note',
        };
    }

    private static function isHtml(string $body): bool
    {
        $start = ltrim($body);

        return stripos($start, '<!doctype') === 0 || stripos($start, '<html') === 0;
    }

    /** A row of the Support Policy table in Laravel's releases.md: `| 13 | 8.3 - 8.5 | March 17th, 2026 | ...`. */
    private const LARAVEL_ROW = '/^\|\s*(\d+)\s*\|\s*(\d+\.\d+)\s*-\s*(\d+\.\d+)\s*\|/';

    /** The last minor of a closed PHP major, so a range across majors (Laravel 8: 7.3 - 8.1) expands. Facts, not announcements. */
    private const LAST_MINOR = [5 => 6, 7 => 4];

    /**
     * Laravel's releases.md (laravel/docs, the newest major's branch): the PHP lines the Support
     * Policy table announces for each Laravel major, as major => X.Y lines, oldest first.
     *
     * Only rows under the "Support Policy" heading count, up to the next heading. Null when that
     * section is gone or no row in it parses: a layout this cannot read claims nothing, so the
     * page shows Laravel as unknown rather than guess. A major with no row is simply absent.
     *
     * @return array<int, list<string>>|null
     */
    public static function laravelSupport(string $body): ?array
    {
        if (self::isHtml($body)) {
            return null;
        }
        $out = [];
        $inside = false;
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s/', $line) === 1) {
                if ($inside) {
                    break;
                }
                $inside = preg_match('/^#{1,6}\s+Support Policy\s*$/i', $line) === 1;

                continue;
            }
            if (!$inside || preg_match(self::LARAVEL_ROW, trim($line), $m) !== 1) {
                continue;
            }
            $lines = self::phpRange($m[2], $m[3]);
            if ($lines !== null) {
                $out[(int) $m[1]] = $lines;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Every PHP line from one X.Y to another, both included; null when the range runs backward or
     * crosses a major whose last minor is not known.
     *
     * @return list<string>|null
     */
    private static function phpRange(string $from, string $to): ?array
    {
        [$fromMajor, $fromMinor] = array_map('intval', explode('.', $from));
        [$toMajor, $toMinor] = array_map('intval', explode('.', $to));
        if ($fromMajor > $toMajor || ($fromMajor === $toMajor && $fromMinor > $toMinor)) {
            return null;
        }
        $lines = [];
        for ($major = $fromMajor; $major <= $toMajor; $major++) {
            $last = $major === $toMajor ? $toMinor : (self::LAST_MINOR[$major] ?? null);
            if ($last === null) {
                return null;
            }
            for ($minor = $major === $fromMajor ? $fromMinor : 0; $minor <= $last; $minor++) {
                $lines[] = $major . '.' . $minor;
            }
        }

        return $lines;
    }

    /**
     * CREED's own framework x PHP matrix (data/framework-php-tests.json, written by
     * `php bin/framework-matrix.php report`): every cell's result, keyed "<package>@<major>",
     * then PHP line. Null when the file is not that matrix.
     *
     * @return array{generated: ?string, cells: array<string, array<string, array{state: string, php_version: ?string, date: ?string, version: ?string, step: ?string, first_error: ?string}>>}|null
     */
    public static function frameworkTests(string $body): ?array
    {
        $data = self::json($body);
        if (!is_array($data) || !is_array($data['frameworks'] ?? null) || !is_array($data['cells'] ?? null)) {
            return null;
        }
        $keys = [];
        foreach ($data['frameworks'] as $f) {
            if (is_array($f) && is_string($f['id'] ?? null) && is_string($f['package'] ?? null) && is_int($f['major'] ?? null)) {
                $keys[$f['id']] = $f['package'] . '@' . $f['major'];
            }
        }
        if ($keys === []) {
            return null;
        }
        $text = static fn(mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;
        $cells = [];
        foreach ($data['cells'] as $c) {
            if (!is_array($c) || !is_string($c['framework'] ?? null) || !isset($keys[$c['framework']]) || !is_string($c['php_line'] ?? null) || !is_string($c['state'] ?? null)) {
                continue;
            }
            $version = is_array($c['framework_package'] ?? null) ? $text($c['framework_package']['version'] ?? null) : null;
            $date = $text($c['date'] ?? null);
            $cells[$keys[$c['framework']]][$c['php_line']] = [
                'state' => $c['state'],
                'php_version' => $text($c['php_version'] ?? null),
                'date' => $date === null ? null : substr($date, 0, 10),
                'version' => $version === null ? null : ltrim($version, 'v'),
                'step' => $text($c['step'] ?? null),
                'first_error' => $text($c['first_error'] ?? null),
            ];
        }

        return ['generated' => $text($data['generated'] ?? null), 'cells' => $cells];
    }

    /**
     * Packagist p2 metadata (minified or not): the newest stable release of
     * each of the newest majors, with its `require.php`.
     *
     * @return list<array{version: string, major: string, released: ?string, php: ?string}>|null
     */
    public static function packagist(string $body, string $package, int $majors = 2): ?array
    {
        $data = self::json($body);
        $list = $data['packages'][$package] ?? null;
        if (!is_array($list) || $list === []) {
            return null;
        }
        $minified = ($data['minified'] ?? null) === 'composer/2.0';
        $best = [];
        $carry = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ($minified) {
                foreach ($entry as $k => $v) {
                    if ($v === '__unset') {
                        unset($carry[$k]);
                    } else {
                        $carry[$k] = $v;
                    }
                }
                $entry = $carry;
            }
            $version = is_string($entry['version'] ?? null) ? ltrim($entry['version'], 'v') : '';
            if (!preg_match('/^(\d+)\.\d+(\.\d+)*$/', $version, $m)) {
                continue;
            }
            $major = $m[1];
            if (isset($best[$major]) && version_compare($best[$major]['version'], $version, '>=')) {
                continue;
            }
            $require = is_array($entry['require'] ?? null) ? $entry['require'] : [];
            $best[$major] = [
                'version' => $version,
                'major' => $major,
                'released' => self::day($entry['time'] ?? null),
                'php' => is_string($require['php'] ?? null) ? $require['php'] : null,
            ];
        }
        if ($best === []) {
            return null;
        }
        uksort($best, static fn(int|string $a, int|string $b): int => (int) $b <=> (int) $a);

        return array_slice(array_values($best), 0, max(1, $majors));
    }

    /** 8.5.13 -> 8.5 */
    public static function branchOf(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    public static function isFinal(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+$/', $version);
    }

    /**
     * @return array<mixed>|null
     */
    private static function json(string $body): ?array
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** A Y-m-d day from an ISO stamp, a plain day or php.net's "19 Nov 2026". */
    private static function day(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) {
            return $m[1];
        }

        return self::looseDay($value);
    }

    private static function looseDay(string $value): ?string
    {
        $value = trim(str_replace(',', '', $value));
        foreach (['!j M Y', '!d M Y', '!M j Y', '!M d Y', '!j F Y', '!F j Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($d !== false && DateTimeImmutable::getLastErrors() === false) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private static function dayInText(string $text): ?string
    {
        $months = self::MONTHS;
        if (preg_match("/\\b(\\d{1,2})(?:st|nd|rd|th)?\\s+({$months})\\s+(\\d{4})\\b/i", $text, $m)) {
            return self::looseDay($m[1] . ' ' . ucfirst(strtolower($m[2])) . ' ' . $m[3]);
        }
        if (preg_match("/\\b({$months})\\s+(\\d{1,2})(?:st|nd|rd|th)?,?\\s+(\\d{4})\\b/i", $text, $m)) {
            return self::looseDay(ucfirst(strtolower($m[1])) . ' ' . $m[2] . ' ' . $m[3]);
        }
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /** An ISO 8601 instant normalized to UTC, or null. */
    private static function instant(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
