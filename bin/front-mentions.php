<?php

declare(strict_types=1);

/**
 * Holds the front page's stack-project version mentions to an allowlist.
 *
 * The front page names the stack's projects with versions in prose ("since
 * PROCTOR 0.10.0", "HELMET 1.0.0 sends no header", "On Packagist, 0.1.12");
 * nothing else checks those numbers. This tool scans site/index.html and
 * site/index.md for a stack project name standing near a three-part version
 * and compares the findings with site/version-mentions.txt.
 *
 * A mention is a three-part version X.Y.Z whose own line carries a stack
 * project name before it: the nearest such name wins, so "six Preflight
 * components to the 0.9.0 tag" reads as Preflight. When the line names
 * nothing, the tool looks up to eight lines back for the name (the Packagist
 * line in Appendix A.1 reads as CREED) and stops at the first intervening
 * line that carries its own three-part version or belongs to the
 * adopted-editions table, so GitLab's 19.5.1 in the errata is never
 * attributed to HELMET's 1.0.0 five lines up. Two-part versions ("PROCTOR
 * 0.7", "MySQL 5.6") are not mentions, a name inside a path or filename
 * (waynestate/creed, .squire/) does not count, and a version behind a colon
 * (the composer:2.11.1 docker tag) is not one. The adopted-editions table
 * (Table 2-1) is skipped outright: its cells are written and checked by
 * bin/front-versions.php.
 *
 * Usage:
 *   php bin/front-mentions.php [--root=DIR]            rewrite site/version-mentions.txt
 *   php bin/front-mentions.php [--root=DIR] --check    fail when list and page disagree
 *
 * Exit codes:
 *   0  written, or (with --check) every mention is listed and every entry present
 *   1  (with --check) a mention is unlisted, or an entry has no mention on the page
 *   2  the tool itself could not run (bad options, missing or unreadable files)
 *
 * One PHP command, no Composer packages, so it runs the same on Windows,
 * macOS and Linux. The page's word is checked against the record, the way
 * Creed Bratton's alibi is: every single time; unlike his, it holds.
 */

function frontMentionsUsage(): never
{
    fwrite(STDERR, "Usage: php bin/front-mentions.php [--root=DIR] [--check]\n");
    fwrite(STDERR, "  --root=DIR  the repo root holding site/index.html, site/index.md and site/version-mentions.txt (default: this repo)\n");
    fwrite(STDERR, "  --check     write nothing; exit 1 when the allowlist and the front page disagree\n");
    exit(2);
}

function frontMentionsFail(string $message): never
{
    fwrite(STDERR, 'front-mentions: ' . $message . "\n");
    exit(2);
}

/**
 * The stack's projects, lowercase to their printed name.
 *
 * @return array<string, string>
 */
function frontMentionsNames(): array
{
    return [
        'wayneui' => 'WayneUI',
        'creed' => 'CREED',
        'proctor' => 'PROCTOR',
        'sesame' => 'SESAME',
        'helmet' => 'HELMET',
        'sourdough' => 'Sourdough',
        'preflight' => 'Preflight',
        'pinstripe' => 'Pinstripe',
        'booster' => 'Booster',
        'rollcall' => 'Rollcall',
        'drawbridge' => 'Drawbridge',
        'squire' => 'Squire',
        'advisor' => 'ADVISOR',
        'weld' => 'WELD',
        'parity' => 'Parity',
        'playbook' => 'Playbook',
        'rolodex' => 'Rolodex',
        'teller' => 'Teller',
    ];
}

/**
 * A stack project name standing alone or as the vendor segment of a package
 * path (proctor/laravel-eol): not reached through a dot, slash, hyphen or
 * word character (which would make it waynestate/creed or .squire/), and not
 * part of a longer word, filename or hyphenated compound (creed.js,
 * preflight-pinned).
 */
function frontMentionsNamePattern(): string
{
    $names = array_keys(frontMentionsNames());
    usort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    $alternatives = [];
    foreach ($names as $name) {
        $alternatives[] = '(?<![\w./-])' . preg_quote($name, '~') . '(?![\w.-])';
    }

    return '~' . implode('|', $alternatives) . '~i';
}

/**
 * The one <table data-adopt> element of the page as [start, end], or null
 * when the page is not the HTML front page.
 *
 * @return array{0: int, 1: int}|null
 */
function frontMentionsAdoptTable(string $html): ?array
{
    if (preg_match('~<table\b[^>]*\bdata-adopt\b[^>]*>~', $html, $tag, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }
    $start = (int) $tag[0][1];
    $end = strpos($html, '</table>', $start);
    if ($end === false) {
        return null;
    }

    return [$start, $end + strlen('</table>')];
}

/**
 * The lines of Table 2-1 in the Markdown twin: its header row plus the
 * contiguous pipe rows under it, as 0-based line numbers.
 *
 * @param list<string> $lines
 *
 * @return list<int>
 */
function frontMentionsMarkdownTable(array $lines): array
{
    $excluded = [];
    $header = null;
    foreach ($lines as $i => $line) {
        if ($header === null) {
            if (preg_match('~^\|\s*Runtime\s*\|.*\bLatest patch\b~', ltrim($line)) === 1) {
                $header = $i;
            }
            continue;
        }
        if (!str_starts_with(ltrim($line), '|')) {
            break;
        }
        $excluded[] = $i;
    }
    if ($header !== null) {
        array_unshift($excluded, $header);
    }

    return $excluded;
}

/**
 * The scanned file's lines as [offset, raw, stripped]: HTML tags removed
 * and whitespace collapsed, so distances are measured in words, not markup.
 *
 * @return list<array{0: int, 1: string, 2: string}>
 */
function frontMentionsLines(string $content, bool $html): array
{
    $out = [];
    foreach (preg_split('~\r\n|\r|\n~', $content, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as $part) {
        $raw = (string) $part[0];
        $stripped = $html ? strip_tags($raw) : $raw;
        $out[] = [(int) $part[1], $raw, trim((string) preg_replace('~\s+~', ' ', $stripped))];
    }

    return $out;
}

/**
 * The nearest stack name in $text (its last occurrence), or null.
 *
 * @param array<string, string> $names
 */
function frontMentionsNearestName(string $text, string $pattern, array $names): ?string
{
    $name = null;
    preg_match_all($pattern, $text, $hits);
    foreach ($hits[0] as $hit) {
        $name = $names[strtolower((string) $hit)] ?? null;
    }

    return $name;
}

/**
 * Every stack-project version mention in the file, as
 * [canonical name, version, 1-based line number].
 *
 * @param list<array{0: int, 1: string, 2: string}> $lines
 * @param array{0: int, 1: int}|null $skipRange
 * @param list<int> $skipLines
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function frontMentionsScan(array $lines, ?array $skipRange, array $skipLines): array
{
    $names = frontMentionsNames();
    $namePattern = frontMentionsNamePattern();
    $versionPattern = '~(?<![:\w.])(\d{1,4}\.\d{1,3}\.\d{1,4})(?![\d.])~';
    $excluded = array_flip($skipLines);
    if ($skipRange !== null) {
        foreach ($lines as $i => $line) {
            if ($line[0] < $skipRange[1] && $line[0] + strlen($line[1]) > $skipRange[0]) {
                $excluded[$i] = true;
            }
        }
    }

    $mentions = [];
    foreach ($lines as $i => [, , $text]) {
        if ($text === '' || isset($excluded[$i])) {
            continue;
        }

        preg_match_all($versionPattern, $text, $versions, PREG_OFFSET_CAPTURE);
        foreach ($versions[1] as $match) {
            $version = (string) $match[0];
            $name = frontMentionsNearestName(substr($text, 0, (int) $match[1]), $namePattern, $names);

            if ($name === null) {
                // The line names nothing: look up to eight lines back for the
                // subject of the sentence. A line with its own three-part
                // version, or a line of the adopted-editions table, ends the
                // search: this version belongs to its own sentence.
                for ($j = $i - 1; $j >= 0 && $i - $j <= 8; $j--) {
                    if ($lines[$j][2] === '') {
                        continue;
                    }
                    if (isset($excluded[$j]) || preg_match('~\d{1,4}\.\d{1,3}\.\d{1,4}~', $lines[$j][2]) === 1) {
                        break;
                    }
                    $name = frontMentionsNearestName($lines[$j][2], $namePattern, $names);
                    if ($name !== null) {
                        break;
                    }
                }
            }

            if ($name !== null) {
                $mentions[] = [$name, $version, $i + 1];
            }
        }
    }

    return $mentions;
}

/**
 * The allowlist's entries as canonical "Name version" strings.
 *
 * @return list<string>
 */
function frontMentionsReadAllowlist(string $path, bool $required): array
{
    if (!is_file($path)) {
        if ($required) {
            frontMentionsFail("cannot read {$path}");
        }

        return [];
    }
    $names = frontMentionsNames();
    $entries = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('~^(\S+)\s+(\S+)$~', $line, $m) !== 1) {
            frontMentionsFail("site/version-mentions.txt: bad entry '{$line}' (want \"<Name> <version>\")");
        }
        $canonical = $names[strtolower($m[1])] ?? null;
        if ($canonical === null) {
            frontMentionsFail("site/version-mentions.txt: '{$m[1]}' is not a stack project name");
        }
        $entries[] = $canonical . ' ' . $m[2];
    }

    return $entries;
}

/**
 * The allowlist text: a comment header, then one "Name version" per line,
 * names first and versions in numeric order.
 *
 * @param list<array{0: string, 1: string, 2: int}> $mentions
 */
function frontMentionsRender(array $mentions): string
{
    $unique = [];
    foreach ($mentions as [$name, $version]) {
        $unique[$name . ' ' . $version] = true;
    }
    $entries = array_keys($unique);
    usort($entries, static function (string $a, string $b): int {
        [$an, $av] = explode(' ', $a, 2);
        [$bn, $bv] = explode(' ', $b, 2);

        return strcmp($an, $bn) ?: version_compare($av, $bv);
    });

    $out = "# The front page's stack-project version mentions, held by bin/front-mentions.php.\n"
        . "# One \"<Name> <version>\" per line; regenerate with: php bin/front-mentions.php\n";
    foreach ($entries as $entry) {
        $out .= $entry . "\n";
    }

    return $out;
}

/** @param list<string> $argv */
function frontMentionsMain(array $argv): int
{
    $root = dirname(__DIR__);
    $check = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $check = true;
        } elseif (str_starts_with($arg, '--root=')) {
            $root = substr($arg, strlen('--root='));
        } elseif ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Usage: php bin/front-mentions.php [--root=DIR] [--check]\n");
            return 0;
        } else {
            frontMentionsUsage();
        }
    }

    $files = ['site/index.html' => true, 'site/index.md' => false];
    $contents = [];
    foreach ($files as $relative => $html) {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            frontMentionsFail("cannot read {$path}");
        }
        $contents[$relative] = (string) file_get_contents($path);
    }

    $mentions = [];
    foreach ($contents as $relative => $content) {
        $html = $files[$relative];
        $lines = frontMentionsLines($content, $html);
        $skipRange = $html ? frontMentionsAdoptTable($content) : null;
        $skipLines = $html ? [] : frontMentionsMarkdownTable(array_column($lines, 1));
        foreach (frontMentionsScan($lines, $skipRange, $skipLines) as [$name, $version, $line]) {
            $mentions[] = [$relative, $line, $name, $version];
        }
    }

    $allowlistPath = $root . '/site/version-mentions.txt';
    $listed = [];
    foreach (frontMentionsReadAllowlist($allowlistPath, $check) as $entry) {
        $listed[$entry] = true;
    }

    $mentioned = [];
    $unlisted = [];
    foreach ($mentions as [$relative, $line, $name, $version]) {
        $key = $name . ' ' . $version;
        $mentioned[$key] = true;
        if (!isset($listed[$key])) {
            $unlisted[] = "{$relative}:{$line}: unlisted mention \"{$key}\"; verify the version is correct, then update site/version-mentions.txt (php bin/front-mentions.php)";
        }
    }
    $stale = [];
    foreach (array_keys($listed) as $key) {
        if (!isset($mentioned[$key])) {
            $stale[] = "site/version-mentions.txt: entry \"{$key}\" has no mention on the front page; drop it or restore the sentence";
        }
    }

    if ($unlisted === [] && $stale === []) {
        echo 'front-mentions: ' . count($mentions) . " mentions on the front page, all listed\n";
        return 0;
    }

    if ($check) {
        fwrite(STDERR, "front-mentions: site/version-mentions.txt and the front page disagree\n");
        foreach (array_merge($unlisted, $stale) as $problem) {
            fwrite(STDERR, "  {$problem}\n");
        }
        return 1;
    }

    $rendered = frontMentionsRender(array_map(static fn(array $m): array => [$m[2], $m[3], $m[1]], $mentions));
    if (file_put_contents($allowlistPath, $rendered) === false) {
        frontMentionsFail("cannot write {$allowlistPath}");
    }
    echo 'front-mentions: site/version-mentions.txt rewritten (' . count($mentions) . " mentions)\n";

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(frontMentionsMain($_SERVER['argv'] ?? []));
}
