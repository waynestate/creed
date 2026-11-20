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
                if (preg_match('/(\d{1,2}:\d{2})\s*(?:and|to|-|–)\s*(\d{1,2}:\d{2})\s*UTC/i', $e['text'], $t)) {
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
     * wiki.php.net todo/phpNN?do=export_raw: the release timetable rows.
     *
     * @return array{ga: ?string, rows: list<array{date: string, label: string, skipped: bool}>}|null
     */
    public static function wiki(string $body): ?array
    {
        if (stripos(ltrim($body), '<!doctype') === 0 || stripos(ltrim($body), '<html') === 0) {
            return null;
        }
        $rows = [];
        $ga = null;
        if (preg_match_all('/^\|\s*([A-Z][a-z]{2,8}\.?\s+\d{1,2},?\s+\d{4})\s*\|\s*(.*?)\s*\|?\s*$/m', $body, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $date = self::looseDay($m[1]);
                if ($date === null) {
                    continue;
                }
                $skipped = (bool) preg_match('#<del>|\bskipped\b#i', $m[2]);
                $label = (string) preg_replace('/\[\[[^|\]]*\|([^\]]*)\]\]/', '$1', $m[2]);
                $label = trim((string) preg_replace('/\s+/', ' ', strip_tags(str_replace('**', '', $label))));
                $rows[] = ['date' => $date, 'label' => $label, 'skipped' => $skipped];
                if (!$skipped && preg_match('/^(GA|General availability)\b/i', $label)) {
                    $ga = $date;
                }
            }
        }

        return $rows === [] ? null : ['ga' => $ga, 'rows' => $rows];
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
