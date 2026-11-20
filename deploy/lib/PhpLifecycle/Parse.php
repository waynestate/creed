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

    /** A matrix larger than this is not expanded: an exclude then drops a line only when it names nothing else. */
    private const MATRIX_CAP = 4096;

    /** The matrix key that holds the PHP version: `php`, `php-version`, `php_versions` and the like. */
    private const PHP_KEY = '/^php(?:[-_]?versions?)?$/i';

    /** One `key: value` pair; the value is optional (a block follows). */
    private const YAML_PAIR = '/^([A-Za-z0-9_-]+|"[^"]*"|\'[^\']*\')\s*:(?:\s+(.*))?$/';

    /**
     * A GitHub Actions workflow file: the PHP lines its matrices run, as X.Y, oldest first.
     *
     * Every `strategy.matrix` block counts: the PHP key's list (`php:` or `php-version:`,
     * flow `[8.3, '8.4']` or block `- 8.3` form, quoted or not), every `include:` entry that
     * names a PHP value, and `exclude:` entries, which drop a line only when they remove
     * every combination it was in. Laravel 13 excludes PHPUnit 13 on 8.3; that is not
     * excluding 8.3. `nightly` and `${{ ... }}` expressions are not lines.
     *
     * Null when no matrix names a PHP value: a workflow this cannot read is not a workflow
     * that tests nothing. Not a YAML parser, just the shapes workflows give a matrix. Like
     * Creed Bratton's resume, it reads what is written down and asks no follow-up questions.
     *
     * @return list<string>|null
     */
    public static function workflowPhp(string $body): ?array
    {
        if (self::isHtml($body) || !preg_match('/^jobs\s*:/m', $body)) {
            return null;
        }
        $lines = array_map(self::yamlUncomment(...), preg_split('/\R/', str_replace("\t", '    ', $body)) ?: []);
        $count = count($lines);
        $found = null;
        for ($i = 0; $i < $count; $i++) {
            if (!preg_match('/^(\s*)matrix\s*:\s*$/', $lines[$i], $m)) {
                continue;
            }
            $indent = strlen($m[1]);
            $block = [];
            $j = $i + 1;
            for (; $j < $count; $j++) {
                if (trim($lines[$j]) === '') {
                    continue;
                }
                if (self::indentOf($lines[$j]) <= $indent) {
                    break;
                }
                $block[] = $lines[$j];
            }
            $i = $j - 1;
            $got = self::matrixPhp(self::yamlMap($block));
            if ($got !== null) {
                $found = array_merge($found ?? [], $got);
            }
        }
        if ($found === null) {
            return null;
        }
        $found = array_values(array_unique($found));
        usort($found, static fn(string $a, string $b): int => version_compare($a, $b));

        return $found;
    }

    /**
     * The PHP lines one parsed matrix runs, or null when it names no PHP version.
     *
     * @param array<string, list<string|array<string, string|list<string>>>> $map
     *
     * @return list<string>|null
     */
    private static function matrixPhp(array $map): ?array
    {
        $include = array_values(array_filter($map['include'] ?? [], is_array(...)));
        $exclude = array_values(array_filter($map['exclude'] ?? [], is_array(...)));
        $dims = [];
        foreach (array_diff_key($map, ['include' => true, 'exclude' => true]) as $k => $list) {
            $dims[$k] = array_map(static fn(string|array $v): string => is_string($v) ? $v : (string) json_encode($v), $list);
        }
        $key = null;
        foreach (array_merge(array_keys($dims), ...array_map(array_keys(...), $include)) as $k) {
            if (preg_match(self::PHP_KEY, $k)) {
                $key = $k;
                break;
            }
        }
        if ($key === null) {
            return null;
        }

        $values = [];
        if (isset($dims[$key])) {
            $combos = [[]];
            foreach ($dims as $k => $list) {
                $next = [];
                foreach ($combos as $c) {
                    foreach ($list as $v) {
                        $next[] = $c + [$k => $v];
                    }
                }
                $combos = $next;
                if (count($combos) > self::MATRIX_CAP) {
                    $combos = null;
                    break;
                }
            }
            if ($combos === null) {
                foreach ($dims[$key] as $v) {
                    if (!in_array([$key => $v], $exclude, true)) {
                        $values[] = $v;
                    }
                }
            } else {
                foreach ($combos as $c) {
                    foreach ($exclude as $e) {
                        if (self::matrixMatches($c, $e)) {
                            continue 2;
                        }
                    }
                    $values[] = $c[$key];
                }
            }
        }
        // Includes come after excludes (GitHub's order), so an include always runs.
        foreach ($include as $e) {
            $v = $e[$key] ?? [];
            foreach (is_array($v) ? $v : [$v] as $one) {
                $values[] = $one;
            }
        }

        $line = '/^(\d+)\.(\d+)(?:\.\d+)?$/';
        // Named no version at all (only nightly, or an expression): not read, rather than "tests nothing".
        if (preg_grep($line, array_merge($dims[$key] ?? [], $values)) === []) {
            return null;
        }
        $out = [];
        foreach ($values as $v) {
            if (preg_match($line, $v, $m)) {
                $out[$m[1] . '.' . $m[2]] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * Does an exclude entry match a combination? Every key it names must be there with that value.
     *
     * @param array<string, string> $combo
     * @param array<string, string|list<string>> $exclude
     */
    private static function matrixMatches(array $combo, array $exclude): bool
    {
        if ($exclude === []) {
            return false;
        }
        foreach ($exclude as $k => $v) {
            if (!is_string($v) || ($combo[$k] ?? null) !== $v) {
                return false;
            }
        }

        return true;
    }

    /**
     * One level of a YAML block mapping: each key's list of values. A scalar is a list of one.
     *
     * @param list<string> $block
     *
     * @return array<string, list<string|array<string, string|list<string>>>>
     */
    private static function yamlMap(array $block): array
    {
        $out = [];
        if ($block === []) {
            return $out;
        }
        $base = self::indentOf($block[0]);
        $count = count($block);
        for ($i = 0; $i < $count; $i++) {
            if (self::indentOf($block[$i]) !== $base || !preg_match(self::YAML_PAIR, trim($block[$i]), $m)) {
                continue;
            }
            $value = trim($m[2] ?? '');
            $children = [];
            // A block list may sit at the key's own indent ("include:" then "- php: 8.5" under it).
            while ($i + 1 < $count && (self::indentOf($block[$i + 1]) > $base
                || ($value === '' && self::indentOf($block[$i + 1]) === $base && str_starts_with(trim($block[$i + 1]), '-')))) {
                $children[] = $block[++$i];
            }
            $out[self::unquote($m[1])] = match (true) {
                str_starts_with($value, '[') => self::flowList($value . ' ' . implode(' ', array_map('trim', $children))),
                $value !== '' => [self::unquote($value)],
                default => self::blockList($children),
            };
        }

        return $out;
    }

    /**
     * A YAML block list: scalars (`- 8.3`) or mappings (`- php: 8.5` and the keys under it).
     *
     * @param list<string> $lines
     *
     * @return list<string|array<string, string|list<string>>>
     */
    private static function blockList(array $lines): array
    {
        $items = [];
        if ($lines === []) {
            return $items;
        }
        $dash = self::indentOf($lines[0]);
        $current = null;
        foreach ($lines as $line) {
            $text = trim($line);
            if (self::indentOf($line) === $dash && str_starts_with($text, '-')) {
                if ($current !== null) {
                    $items[] = $current;
                }
                $rest = trim(substr($text, 1));
                $current = preg_match(self::YAML_PAIR, $rest, $m)
                    ? [self::unquote($m[1]) => self::yamlValue(trim($m[2] ?? ''))]
                    : ($rest === '' ? [] : self::unquote($rest));
            } elseif (is_array($current) && self::indentOf($line) > $dash && preg_match(self::YAML_PAIR, $text, $m)) {
                $current[self::unquote($m[1])] = self::yamlValue(trim($m[2] ?? ''));
            }
        }
        if ($current !== null) {
            $items[] = $current;
        }

        return $items;
    }

    /**
     * @return string|list<string>
     */
    private static function yamlValue(string $value): string|array
    {
        return str_starts_with($value, '[') ? self::flowList($value) : self::unquote($value);
    }

    /**
     * `[8.3, '8.4', "8.5"]`, up to its closing bracket.
     *
     * @return list<string>
     */
    private static function flowList(string $text): array
    {
        $items = [];
        $buf = '';
        $quote = null;
        $len = strlen($text);
        for ($i = (int) strpos($text, '[') + 1; $i < $len; $i++) {
            $ch = $text[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $buf .= $ch;
            } elseif ($ch === ',' || $ch === ']') {
                if (trim($buf) !== '') {
                    $items[] = self::unquote($buf);
                }
                $buf = '';
                if ($ch === ']') {
                    break;
                }
            } else {
                $buf .= $ch;
            }
        }
        // The closing bracket on a line of its own, back at the key's indent, is not in $text.
        if (trim($buf) !== '') {
            $items[] = self::unquote($buf);
        }

        return $items;
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    /** A line without its `# comment` (a # inside quotes, or glued to a word, is not one). */
    private static function yamlUncomment(string $line): string
    {
        $quote = null;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($quote !== null) {
                $quote = $ch === $quote ? null : $quote;
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '#' && ($i === 0 || ctype_space($line[$i - 1]))) {
                return rtrim(substr($line, 0, $i));
            }
        }

        return rtrim($line);
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
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
