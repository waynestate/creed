<?php

declare(strict_types=1);

/**
 * Writes the front page's Table 2-1 (the adopted-editions table in
 * site/index.html) from policy/policy.json: each row's active-until and
 * security-until dates and its latest patch, or n/a when the line has none.
 * Current lines (their second cell carries colspan) and the group headers
 * are left alone, and every byte outside those three cells is kept.
 *
 * Usage:
 *   php bin/front-versions.php [--root=DIR]            write site/index.html
 *   php bin/front-versions.php [--root=DIR] --check    fail if the table is stale
 *
 * Exit codes:
 *   0  written, or (with --check) the table matches policy.json
 *   1  (with --check) a cell is stale
 *   2  the tool itself could not run (bad options, missing or invalid files,
 *      a row the policy does not know)
 *
 * One PHP command, no Composer packages, so it runs the same on Windows,
 * macOS and Linux. The cells are written from the record, never trusted as
 * printed: like Creed Bratton's alibi, the story is checked against the
 * record every single time; unlike his, it holds.
 */

function frontVersionsUsage(): never
{
    fwrite(STDERR, "Usage: php bin/front-versions.php [--root=DIR] [--check]\n");
    fwrite(STDERR, "  --root=DIR  the repo root holding policy/policy.json and site/index.html (default: this repo)\n");
    fwrite(STDERR, "  --check     write nothing; exit 1 when Table 2-1 is stale\n");
    exit(2);
}

function frontVersionsFail(string $message): never
{
    fwrite(STDERR, 'front-versions: ' . $message . "\n");
    exit(2);
}

/**
 * The one <table data-adopt> element of the page as [start, end, region].
 *
 * @return array{0: int, 1: int, 2: string}
 */
function frontVersionsRegion(string $html): array
{
    $open = '~<table\b[^>]*\bdata-adopt\b[^>]*>~';
    $tag = [];
    if (preg_match_all($open, $html) !== 1 || preg_match($open, $html, $tag, PREG_OFFSET_CAPTURE) !== 1) {
        frontVersionsFail('site/index.html must have exactly one <table data-adopt>');
    }
    $start = (int) $tag[0][1];
    $end = strpos($html, '</table>', $start);
    if ($end === false) {
        frontVersionsFail('site/index.html must have exactly one <table data-adopt>');
    }
    $end += strlen('</table>');

    return [$start, $end, substr($html, $start, $end - $start)];
}

/**
 * The five cells of one adopt row, or null when the row is not a plain
 * three-cell row (the head, a group header, or a current line whose second
 * cell carries colspan).
 *
 * @return array{th: string, active: string, security: string, patch: string, state: string}|null
 */
function frontVersionsCells(string $inner): ?array
{
    if (preg_match('~^(<th scope="row">.*?</th>)<td>([^<]*)</td><td>([^<]*)</td><td>([^<]*)</td>(<td>.*</td>)$~s', $inner, $m) !== 1) {
        return null;
    }

    return [
        'th' => $m[1],
        'active' => $m[2],
        'security' => $m[3],
        'patch' => $m[4],
        'state' => $m[5],
    ];
}

/**
 * @param array<string, mixed> $line
 */
function frontVersionsDate(array $line, string $component, string $version, string $field): string
{
    $value = $line[$field] ?? null;
    if (!is_string($value) || $value === '') {
        frontVersionsFail("Table 2-1 row {$component} {$version} has no {$field} in policy.json");
    }

    return $value;
}

/**
 * @param array<string, mixed> $line
 */
function frontVersionsPatch(array $line, string $component, string $version): string
{
    $value = $line['latest_patch'] ?? null;
    if ($value === null) {
        return 'n/a';
    }
    if (!is_string($value) || $value === '') {
        frontVersionsFail("Table 2-1 row {$component} {$version} has a latest_patch that is not a string");
    }

    return $value;
}

/**
 * Rewrites the region's rows against the policy.
 *
 * @param array<string, array<string, mixed>> $components
 *
 * @return array{0: string, 1: list<array{0: string, 1: string, 2: string, 3: string, 4: string}>}
 *         the new region, and the stale cells as [component, version, label, page, policy]
 */
function frontVersionsRewrite(string $region, array $components): array
{
    $stale = [];
    $out = '';
    $written = 0;

    while (preg_match('~<tr\b[^>]*>.*?</tr>~s', $region, $row, PREG_OFFSET_CAPTURE, $written) === 1) {
        $pos = (int) $row[0][1];
        $full = (string) $row[0][0];
        $out .= substr($region, $written, $pos - $written);
        $written = $pos + strlen($full);

        if (preg_match('~^<tr\b([^>]*)>~', $full, $tag) !== 1) {
            $out .= $full;
            continue;
        }
        $attrs = (string) $tag[1];
        $component = preg_match('/data-c="([^"]*)"/', $attrs, $c) === 1 ? $c[1] : null;
        $version = preg_match('/data-v="([^"]*)"/', $attrs, $v) === 1 ? $v[1] : null;
        $inner = substr($full, strlen('<tr' . $attrs . '>'), -strlen('</tr>'));
        $cells = $component !== null && $version !== null ? frontVersionsCells($inner) : null;
        if ($cells === null) {
            // A group header or a current line: byte-identical.
            $out .= $full;
            continue;
        }

        $lines = $components[$component]['versions'] ?? null;
        $line = is_array($lines) ? ($lines[$version] ?? null) : null;
        if (!is_array($line)) {
            frontVersionsFail("Table 2-1 row {$component} {$version} is not in policy.json");
        }

        $expected = [
            'active' => frontVersionsDate($line, $component, $version, 'active_until'),
            'security' => frontVersionsDate($line, $component, $version, 'security_until'),
            'patch' => frontVersionsPatch($line, $component, $version),
        ];
        $labels = ['active' => 'active until', 'security' => 'security until', 'patch' => 'latest patch'];
        foreach ($expected as $key => $value) {
            if ($cells[$key] !== $value) {
                $stale[] = [$component, $version, $labels[$key], $cells[$key], $value];
            }
        }

        $out .= '<tr' . $attrs . '>' . $cells['th']
            . '<td>' . $expected['active'] . '</td>'
            . '<td>' . $expected['security'] . '</td>'
            . '<td>' . $expected['patch'] . '</td>'
            . $cells['state'] . '</tr>';
    }

    $out .= substr($region, $written);

    return [$out, $stale];
}

/** @param list<string> $argv */
function frontVersionsMain(array $argv): int
{
    $root = dirname(__DIR__);
    $check = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $check = true;
        } elseif (str_starts_with($arg, '--root=')) {
            $root = substr($arg, strlen('--root='));
        } elseif ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Usage: php bin/front-versions.php [--root=DIR] [--check]\n");
            return 0;
        } else {
            frontVersionsUsage();
        }
    }

    $policyPath = $root . '/policy/policy.json';
    $pagePath = $root . '/site/index.html';
    if (!is_file($policyPath)) {
        frontVersionsFail("cannot read {$policyPath}");
    }
    if (!is_file($pagePath)) {
        frontVersionsFail("cannot read {$pagePath}");
    }

    $decoded = json_decode((string) file_get_contents($policyPath), true);
    if (!is_array($decoded)) {
        frontVersionsFail('policy/policy.json is not valid JSON');
    }
    $components = $decoded['components'] ?? null;
    if (!is_array($components)) {
        frontVersionsFail('policy/policy.json has no components object');
    }

    $html = (string) file_get_contents($pagePath);
    [$start, $end, $region] = frontVersionsRegion($html);
    [$newRegion, $stale] = frontVersionsRewrite($region, $components);

    if ($stale === []) {
        echo "front-versions: site/index.html Table 2-1 is current\n";
        return 0;
    }

    if ($check) {
        fwrite(STDERR, "front-versions: site/index.html Table 2-1 is stale; run composer site:versions\n");
        foreach ($stale as [$c, $v, $label, $page, $policy]) {
            fwrite(STDERR, "  {$c} {$v} {$label}: page {$page}, policy {$policy}\n");
        }
        return 1;
    }

    $newHtml = substr($html, 0, $start) . $newRegion . substr($html, $end);
    if (file_put_contents($pagePath, $newHtml) === false) {
        frontVersionsFail("cannot write {$pagePath}");
    }
    echo 'front-versions: site/index.html Table 2-1 updated (' . count($stale) . " cells)\n";

    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(frontVersionsMain($_SERVER['argv'] ?? []));
}
