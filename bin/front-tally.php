<?php

declare(strict_types=1);

/**
 * Writes the front page's counts (site/index.html) from BEST_PRACTICES.md,
 * the record: the meta description's practice count, the title-page tally
 * (its label and the five stamp counts), the contents heading, the "All N
 * are in that file" line, every contents row's clause range and stamp bar,
 * and the errata foot's backlog count. A chapter with no contents row gets
 * one, linked to its heading in BEST_PRACTICES.md and named after it (the
 * "(second pass)" style suffix dropped); a row whose chapter is gone is
 * dropped. Every other byte is kept, so a row's name and link stay as the
 * page wrote them, and the stamp a reprinted clause wears stays with
 * FrontPageTallyTest, because changing it means rereading the clause.
 *
 * Usage:
 *   php bin/front-tally.php [--root=DIR]            write site/index.html
 *   php bin/front-tally.php [--root=DIR] --check    fail if a count is stale
 *
 * Exit codes:
 *   0  written, or (with --check) every count matches BEST_PRACTICES.md
 *   1  (with --check) a count is stale
 *   2  the tool itself could not run (bad options, missing files, a page
 *      without one of the places it writes, a practice without a known stamp)
 *
 * One PHP command, no Composer packages, so it runs the same on Windows,
 * macOS and Linux. Creed Bratton counts the cash drawer by feel and comes up
 * "about right". This counts the book every time and comes up exact.
 */

/** Stamp tag => [the tally modifier, the contents bar class], in printing order. */
const FRONT_TALLY_STAMPS = [
    'Enforced' => ['enforced', 'e'],
    'Checked' => ['checked', 'c'],
    'Partially' => ['partially', 'p'],
    'Documented' => ['documented', 'd'],
    'Not yet' => ['notyet', 'n'],
];

const FRONT_TALLY_WORDS = [
    1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
    'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen', 'Twenty',
];

const FRONT_TALLY_SOURCE = 'https://github.com/waynestate/creed/blob/main/BEST_PRACTICES.md';

function frontTallyUsage(): never
{
    fwrite(STDERR, "Usage: php bin/front-tally.php [--root=DIR] [--check]\n");
    fwrite(STDERR, "  --root=DIR  the repo root holding BEST_PRACTICES.md and site/index.html (default: this repo)\n");
    fwrite(STDERR, "  --check     write nothing; exit 1 when a count on the front page is stale\n");
    exit(2);
}

function frontTallyFail(string $message): never
{
    fwrite(STDERR, 'front-tally: ' . $message . "\n");
    exit(2);
}

/**
 * The record, read the way FrontPageTallyTest reads it: each "## N. Title"
 * chapter, its "### N. " practices, and the "How the stack holds it: `Tag`"
 * line each one ends with; plus the numbered items under "## Gaps for the
 * backlog".
 *
 * @return array{chapters: array<int, array{title: string, tags: list<string>}>, backlog: int}
 */
function frontTallyRecord(string $text): array
{
    $titles = [];
    $tags = [];
    $chapter = null;
    $practice = null;
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (preg_match('/^#{2,3} /', $line) === 1 && $practice !== null) {
            frontTallyFail("practice {$practice} in BEST_PRACTICES.md has no \"How the stack holds it\" line");
        }
        if (preg_match('/^## (\d+)\. (.+)$/', $line, $m) === 1) {
            $chapter = (int) $m[1];
            $titles[$chapter] = trim($m[2]);
            $tags[$chapter] = [];
        } elseif (str_starts_with($line, '## ')) {
            $chapter = null; // back matter: checks, gaps, sources
        } elseif ($chapter !== null && preg_match('/^### (\d+)\. /', $line, $m) === 1) {
            $practice = $m[1];
        } elseif ($chapter !== null && $practice !== null && preg_match('/^How the stack holds it: `([^`]+)`/', $line, $m) === 1) {
            if (!isset(FRONT_TALLY_STAMPS[$m[1]])) {
                frontTallyFail("practice {$practice} in BEST_PRACTICES.md has an unknown stamp `{$m[1]}`");
            }
            $tags[$chapter][] = $m[1];
            $practice = null;
        }
    }
    if ($titles === []) {
        frontTallyFail('BEST_PRACTICES.md has no numbered chapters');
    }
    $chapters = [];
    foreach ($titles as $no => $title) {
        if (($tags[$no] ?? []) === []) {
            frontTallyFail("chapter {$no} in BEST_PRACTICES.md has no practices");
        }
        $chapters[$no] = ['title' => $title, 'tags' => $tags[$no]];
    }

    $backlog = preg_match('/^## Gaps for the backlog\R(.*?)^## /ms', $text, $m) === 1
        ? preg_match_all('/^\d+\. /m', $m[1])
        : 0;

    return ['chapters' => $chapters, 'backlog' => (int) $backlog];
}

/** The anchor GitHub gives a Markdown heading. */
function frontTallySlug(string $heading): string
{
    $slug = strtolower($heading);
    $slug = (string) preg_replace('/[^a-z0-9 _-]/', '', $slug);

    return str_replace(' ', '-', $slug);
}

/** @param list<string> $tags */
function frontTallyBar(array $tags): string
{
    $count = array_count_values($tags);
    $label = [];
    $segments = '';
    foreach (FRONT_TALLY_STAMPS as $tag => [, $class]) {
        if (isset($count[$tag])) {
            $label[] = $count[$tag] . ' ' . strtolower($tag);
            $segments .= '<i class="' . $class . '" style="--n:' . $count[$tag] . '"></i>';
        }
    }

    return '<span class="toc__bar" role="img" aria-label="' . implode(', ', $label) . '">' . $segments . '</span>';
}

/**
 * Replaces the one match of $pattern's group 1 with $value, and records a
 * stale entry when it differs.
 *
 * @param list<array{0: string, 1: string, 2: string}> $stale
 */
function frontTallySet(string $html, string $pattern, string $value, string $label, array &$stale): string
{
    $count = preg_match_all($pattern, $html, $all, PREG_OFFSET_CAPTURE);
    if ($count !== 1) {
        frontTallyFail("site/index.html must have exactly one {$label} (found {$count})");
    }
    [$found, $offset] = $all[1][0];
    if ($found === $value) {
        return $html;
    }
    $stale[] = [$label, $found, $value];

    return substr($html, 0, $offset) . $value . substr($html, $offset + strlen($found));
}

/**
 * Rewrites the contents list (<ol class="toc">) against the record.
 *
 * @param array<int, array{title: string, tags: list<string>}> $chapters
 * @param list<array{0: string, 1: string, 2: string}> $stale
 */
function frontTallyContents(string $html, array $chapters, array &$stale): string
{
    if (preg_match_all('~<ol class="toc"[^>]*>\R(.*?)\R(\s*)</ol>~s', $html, $lists, PREG_OFFSET_CAPTURE) !== 1) {
        frontTallyFail('site/index.html must have exactly one <ol class="toc">');
    }
    [$body, $start] = $lists[1][0];
    $row = '~^(\s*)<li><a href="([^"]*)"><span class="toc__no">(\d+)</span><span class="toc__name">([^<]*)</span><span class="toc__lead" aria-hidden="true"></span><span class="toc__range">([^<]*)</span></a>(<span class="toc__bar"[^>]*>.*?</span>)</li>$~';

    $rows = [];
    $indent = '        ';
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        if (preg_match($row, $line, $m) !== 1) {
            frontTallyFail('a contents row in site/index.html is not in the shape this tool writes: ' . trim($line));
        }
        $indent = $m[1];
        $rows[(int) $m[3]] = ['href' => $m[2], 'name' => $m[4], 'range' => $m[5], 'bar' => $m[6]];
    }

    $out = [];
    foreach ($chapters as $no => $chapter) {
        $range = "§{$no}.1 to §{$no}." . count($chapter['tags']);
        $bar = frontTallyBar($chapter['tags']);
        $current = $rows[$no] ?? null;
        if ($current === null) {
            $heading = $no . '. ' . $chapter['title'];
            $name = trim((string) preg_replace('/\s*\([^)]*\)$/', '', $chapter['title']));
            $current = ['href' => FRONT_TALLY_SOURCE . '#' . frontTallySlug($heading), 'name' => htmlspecialchars($name, ENT_QUOTES), 'range' => '', 'bar' => ''];
            $stale[] = ["contents row {$no}", 'missing', $name];
        } else {
            if ($current['range'] !== $range) {
                $stale[] = ["contents row {$no} range", $current['range'], $range];
            }
            if ($current['bar'] !== $bar) {
                preg_match('/aria-label="([^"]*)"/', $current['bar'], $was);
                preg_match('/aria-label="([^"]*)"/', $bar, $now);
                $stale[] = ["contents row {$no} stamp bar", $was[1] ?? '?', $now[1] ?? '?'];
            }
        }
        $out[] = $indent . '<li><a href="' . $current['href'] . '"><span class="toc__no">' . $no . '</span><span class="toc__name">'
            . $current['name'] . '</span><span class="toc__lead" aria-hidden="true"></span><span class="toc__range">' . $range
            . '</span></a>' . $bar . '</li>';
    }
    foreach (array_diff_key($rows, $chapters) as $no => $gone) {
        $stale[] = ["contents row {$no}", $gone['name'], 'dropped (no such chapter)'];
    }

    $newBody = implode("\n", $out);

    return substr($html, 0, $start) . $newBody . substr($html, $start + strlen($body));
}

/**
 * The page rewritten against the record, and what was stale.
 *
 * @param array{chapters: array<int, array{title: string, tags: list<string>}>, backlog: int} $record
 *
 * @return array{0: string, 1: list<array{0: string, 1: string, 2: string}>}
 */
function frontTallyRewrite(string $html, array $record): array
{
    $stale = [];
    $chapters = $record['chapters'];
    $all = array_merge(...array_values(array_map(static fn(array $c): array => $c['tags'], $chapters)));
    $practices = (string) count($all);
    $totals = array_count_values($all);
    $chapterCount = count($chapters);
    $word = FRONT_TALLY_WORDS[$chapterCount] ?? (string) $chapterCount;

    $html = frontTallySet($html, '~<meta name="description" content="[^"]*?(\d+) numbered practices~', $practices, 'meta description practice count', $stale);
    $html = frontTallySet($html, '~<dl class="tally" aria-label="How the (\d+) practices are held today">~', $practices, 'tally label', $stale);
    foreach (FRONT_TALLY_STAMPS as $tag => [$modifier]) {
        $pattern = '~<div class="tally__item tally__item--' . $modifier . '"><dt>' . preg_quote($tag, '~') . '</dt><dd>(\d+)</dd></div>~';
        $html = frontTallySet($html, $pattern, (string) ($totals[$tag] ?? 0), "tally {$tag} count", $stale);
    }
    $html = frontTallySet($html, '~<h2 id="contents-title">([^<]*)</h2>~', "{$word} chapters, {$practices} practices", 'contents heading', $stale);
    $html = frontTallySet($html, '~\. All (\d+) are in that file~', $practices, '"All N are in that file" count', $stale);
    if ($record['backlog'] > 0) {
        $html = frontTallySet($html, '~The full backlog, (\d+) items, is at~', (string) $record['backlog'], 'errata backlog count', $stale);
    }

    return [frontTallyContents($html, $chapters, $stale), $stale];
}

/** @param list<string> $argv */
function frontTallyMain(array $argv): int
{
    $root = dirname(__DIR__);
    $check = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $check = true;
        } elseif (str_starts_with($arg, '--root=')) {
            $root = substr($arg, strlen('--root='));
        } elseif ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Usage: php bin/front-tally.php [--root=DIR] [--check]\n");
            return 0;
        } else {
            frontTallyUsage();
        }
    }

    $recordPath = $root . '/BEST_PRACTICES.md';
    $pagePath = $root . '/site/index.html';
    if (!is_file($recordPath)) {
        frontTallyFail("cannot read {$recordPath}");
    }
    if (!is_file($pagePath)) {
        frontTallyFail("cannot read {$pagePath}");
    }

    $record = frontTallyRecord((string) file_get_contents($recordPath));
    $html = (string) file_get_contents($pagePath);
    [$newHtml, $stale] = frontTallyRewrite($html, $record);

    if ($stale === []) {
        echo "front-tally: site/index.html counts match BEST_PRACTICES.md\n";
        return 0;
    }

    if ($check) {
        fwrite(STDERR, "front-tally: site/index.html counts are stale against BEST_PRACTICES.md; run composer site:tally\n");
        foreach ($stale as [$label, $page, $want]) {
            fwrite(STDERR, "  {$label}: page {$page}, record {$want}\n");
        }
        return 1;
    }

    if (file_put_contents($pagePath, $newHtml) === false) {
        frontTallyFail("cannot write {$pagePath}");
    }
    echo 'front-tally: site/index.html updated (' . count($stale) . " counts)\n";

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(frontTallyMain($_SERVER['argv'] ?? []));
}
