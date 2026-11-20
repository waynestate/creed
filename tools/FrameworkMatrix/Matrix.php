<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\FrameworkMatrix;

use RuntimeException;
use Waynestate\Creed\Site\PhpLifecycle\Constraint;
use Waynestate\Creed\Site\PhpLifecycle\Parse;

/**
 * The framework x PHP matrix's plan and report: which cells are worth a run
 * (the newest release of each major, and what its `require.php` allows on
 * Packagist), and how the cells' results become `data/framework-php-tests.json`
 * and the docs page's table.
 *
 * A cell `require.php` excludes is "no" and never runs. Every other cell runs
 * in a container and comes back pass, pass-deprecations, fail or refused, or
 * stays "not-run" until it does. Creed Bratton claims he played every venue
 * in town; this file only lists the ones it can prove.
 */
final class Matrix
{
    public const STATES = ['pass', 'pass-deprecations', 'fail', 'refused', 'no', 'no-image', 'not-run'];

    /** One letter per state, for the table. */
    public const LETTERS = ['pass' => 'P', 'pass-deprecations' => 'D', 'fail' => 'F', 'refused' => 'R', 'no' => '-', 'no-image' => '?', 'not-run' => '?'];

    public const SECTION_START = '<!-- framework-matrix:start (written by php bin/framework-matrix.php report; do not edit by hand) -->';

    public const SECTION_END = '<!-- framework-matrix:end -->';

    /**
     * @return array{php_lines: list<string>, run_order: list<string>, image: string, prerelease_image: string, extensions: list<string>, frameworks: list<array{id: string, name: string, major: int, package: string, skeleton: string, skeleton_constraint: string, docroot: string, test: list<string>}>}
     */
    public static function config(string $path): array
    {
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['frameworks'] ?? null) || !is_array($data['php_lines'] ?? null)) {
            throw new RuntimeException("{$path}: not a framework matrix config");
        }
        /** @var array{php_lines: list<string>, run_order: list<string>, image: string, prerelease_image: string, extensions: list<string>, frameworks: list<array{id: string, name: string, major: int, package: string, skeleton: string, skeleton_constraint: string, docroot: string, test: list<string>}>} $data */
        return $data;
    }

    /**
     * The plan: each major's newest release from its Packagist p2 feed, and for
     * every PHP line whether that release's `require.php` allows it.
     *
     * @param array{php_lines: list<string>, run_order: list<string>, frameworks: list<array{id: string, name: string, major: int, package: string, skeleton: string, skeleton_constraint: string}>} $config
     * @param array<string, ?string> $feeds package => p2 body (null when it could not be read)
     *
     * @return array{generated: string, php_lines: list<string>, run_order: list<string>, frameworks: list<array<string, mixed>>, cells: list<array{framework: string, php_line: string, allowed: ?bool}>}
     */
    public static function plan(array $config, array $feeds, string $today): array
    {
        $frameworks = [];
        $cells = [];
        foreach ($config['frameworks'] as $f) {
            $body = $feeds[$f['package']] ?? null;
            $release = null;
            foreach ($body === null ? [] : (Parse::packagist($body, $f['package'], 50) ?? []) as $m) {
                if ((int) $m['major'] === $f['major']) {
                    $release = $m;
                }
            }
            $frameworks[] = [
                'id' => $f['id'],
                'name' => $f['name'],
                'major' => $f['major'],
                'package' => $f['package'],
                'release' => $release === null ? null : ['version' => $release['version'], 'released' => $release['released']],
                'require_php' => $release['php'] ?? null,
                'skeleton' => $f['skeleton'],
                'skeleton_constraint' => $f['skeleton_constraint'],
            ];
            foreach ($config['php_lines'] as $line) {
                $php = $release['php'] ?? null;
                $cells[] = ['framework' => $f['id'], 'php_line' => $line, 'allowed' => $php === null ? null : Constraint::allowsBranch($php, $line)];
            }
        }

        return ['generated' => $today, 'php_lines' => $config['php_lines'], 'run_order' => $config['run_order'], 'frameworks' => $frameworks, 'cells' => $cells];
    }

    /**
     * The cells to run, in run order (lines first, then frameworks in config order).
     * A cell whose `require.php` could not be read runs anyway and lets Composer decide.
     *
     * @param array{run_order: list<string>, frameworks: list<array<string, mixed>>, cells: list<array{framework: string, php_line: string, allowed: ?bool}>} $plan
     *
     * @return list<array{framework: string, php_line: string, run: bool}>
     */
    public static function runList(array $plan): array
    {
        $out = [];
        foreach ($plan['run_order'] as $line) {
            foreach ($plan['cells'] as $cell) {
                if ($cell['php_line'] === $line) {
                    $out[] = ['framework' => $cell['framework'], 'php_line' => $line, 'run' => $cell['allowed'] !== false];
                }
            }
        }

        return $out;
    }

    /**
     * The data file: the plan's frameworks, every cell with its result (a new
     * result replaces the previous file's; a cell that did not run this time
     * keeps what the previous file had), and the image each line ran on.
     *
     * @param array{generated: string, php_lines: list<string>, frameworks: list<array<string, mixed>>, cells: list<array{framework: string, php_line: string, allowed: ?bool}>} $plan
     * @param list<array<string, mixed>> $results cell results from bin/framework-matrix-cell.php
     * @param array<string, mixed>|null $previous the data file as it was
     * @param array<string, array{image: ?string, prerelease: bool}|null> $images line => image ("null" when no image exists)
     *
     * @return array<string, mixed>
     */
    public static function merge(array $plan, array $results, ?array $previous, array $images, string $today): array
    {
        $byKey = [];
        foreach (is_array($previous['cells'] ?? null) ? $previous['cells'] : [] as $old) {
            if (is_array($old) && is_string($old['framework'] ?? null) && is_string($old['php_line'] ?? null)) {
                $byKey[$old['framework'] . '@' . $old['php_line']] = $old;
            }
        }
        foreach ($results as $r) {
            if (is_string($r['framework'] ?? null) && is_string($r['php_line'] ?? null)) {
                $byKey[$r['framework'] . '@' . $r['php_line']] = $r;
            }
        }

        $cells = [];
        foreach ($plan['cells'] as $planned) {
            $key = $planned['framework'] . '@' . $planned['php_line'];
            $line = $planned['php_line'];
            if ($planned['allowed'] === false) {
                $cells[] = ['framework' => $planned['framework'], 'php_line' => $line, 'allowed' => false, 'state' => 'no'];
                continue;
            }
            $result = $byKey[$key] ?? null;
            if ($result !== null && ($result['state'] ?? null) !== 'no' && ($result['state'] ?? null) !== 'not-run') {
                $cells[] = ['framework' => $planned['framework'], 'php_line' => $line, 'allowed' => $planned['allowed']] + $result;
                continue;
            }
            $noImage = array_key_exists($line, $images) && $images[$line] === null;
            $cells[] = ['framework' => $planned['framework'], 'php_line' => $line, 'allowed' => $planned['allowed'], 'state' => $noImage ? 'no-image' : 'not-run'];
        }

        $imageRecord = [];
        foreach ($plan['php_lines'] as $line) {
            $versions = [];
            $image = null;
            $prerelease = false;
            foreach ($cells as $c) {
                if ($c['php_line'] === $line && is_string($c['php_version'] ?? null)) {
                    $versions[] = $c['php_version'];
                    $image = is_string($c['image'] ?? null) ? $c['image'] : $image;
                    $prerelease = $prerelease || ($c['prerelease'] ?? false) === true;
                }
            }
            if ($image === null && isset($images[$line])) {
                $image = $images[$line]['image'];
                $prerelease = $images[$line]['prerelease'];
            }
            $imageRecord[$line] = ['image' => $image, 'php_versions' => array_values(array_unique($versions)), 'prerelease' => $prerelease];
        }

        return [
            '$comment' => 'Written by php bin/framework-matrix.php report from the cells scripts/framework-matrix.ps1 or .sh ran. States: pass, pass-deprecations, fail, refused (Composer refused the PHP platform), no (require.php excludes the line; not run), no-image, not-run. See docs/guides/framework-php-tests.md.',
            'generated' => $today,
            'php_lines' => $plan['php_lines'],
            'images' => $imageRecord,
            'frameworks' => $plan['frameworks'],
            'cells' => $cells,
        ];
    }

    /**
     * The docs page's generated section: the table and a footnote for every cell that is not a clean pass.
     *
     * @param array<string, mixed> $data
     */
    public static function markdown(array $data): string
    {
        /** @var list<string> $lines */
        $lines = $data['php_lines'];
        /** @var list<array<string, mixed>> $frameworks */
        $frameworks = $data['frameworks'];
        /** @var list<array<string, mixed>> $cells */
        $cells = $data['cells'];
        /** @var array<string, array{image: ?string, php_versions: list<string>, prerelease: bool}> $images */
        $images = $data['images'];

        $out = [self::SECTION_START, '', 'Results from ' . self::str($data['generated']) . '.', ''];
        $out[] = '| Framework | ' . implode(' | ', array_map(static fn(string $l): string => 'PHP ' . $l, $lines)) . ' |';
        $out[] = '|---|' . str_repeat('---|', count($lines));
        $notes = [];
        foreach ($frameworks as $f) {
            $id = self::str($f['id']);
            $release = is_array($f['release'] ?? null) ? self::str($f['release']['version']) : 'unknown';
            $row = '| ' . self::str($f['name']) . ' ' . self::str($f['major']) . ' (' . $release . ') |';
            foreach ($lines as $line) {
                $cell = self::cell($cells, $id, $line);
                $state = self::str($cell['state'] ?? 'not-run');
                $letter = self::LETTERS[$state] ?? '?';
                $note = self::note($f, $cell, $images[$line] ?? null);
                if ($note !== null) {
                    $notes[] = $note;
                    $letter .= ' [' . count($notes) . ']';
                }
                $row .= ' ' . $letter . ' |';
            }
            $out[] = $row;
        }
        $out[] = '';
        $out[] = 'P: pass. D: pass with deprecations or warnings. F: fail. R: Composer refused the PHP platform. -: the release\'s `require.php` excludes the line, so it was not run. ?: not run yet, or no official image.';
        $out[] = '';
        if ($notes !== []) {
            foreach ($notes as $i => $note) {
                $out[] = ($i + 1) . '. ' . $note;
            }
            $out[] = '';
        }
        $out[] = '| PHP line | Patch | Image |';
        $out[] = '|---|---|---|';
        foreach ($lines as $line) {
            $img = $images[$line] ?? null;
            $out[] = '| ' . $line . ' | ' . ($img === null || $img['php_versions'] === [] ? 'not run' : implode(', ', $img['php_versions'])) . ($img !== null && $img['prerelease'] ? ' (prerelease)' : '') . ' | ' . ($img === null || $img['image'] === null ? 'none' : '`' . $img['image'] . '`') . ' |';
        }
        $out[] = '';
        $out[] = self::SECTION_END;

        return implode("\n", $out);
    }

    /** The page with its generated section replaced, or the section appended when the markers are missing. */
    public static function replaceSection(string $page, string $section): string
    {
        $start = strpos($page, self::SECTION_START);
        $end = strpos($page, self::SECTION_END);
        if ($start === false || $end === false || $end < $start) {
            return rtrim($page) . "\n\n" . $section . "\n";
        }

        return substr($page, 0, $start) . $section . substr($page, $end + strlen(self::SECTION_END));
    }

    /**
     * @param list<array<string, mixed>> $cells
     *
     * @return array<string, mixed>
     */
    private static function cell(array $cells, string $framework, string $line): array
    {
        foreach ($cells as $c) {
            if (($c['framework'] ?? null) === $framework && ($c['php_line'] ?? null) === $line) {
                return $c;
            }
        }

        return ['state' => 'not-run'];
    }

    /**
     * The footnote for a cell that is not a clean pass on a released PHP, or null.
     *
     * @param array<string, mixed> $framework
     * @param array<string, mixed> $cell
     * @param array{image: ?string, php_versions: list<string>, prerelease: bool}|null $image
     */
    private static function note(array $framework, array $cell, ?array $image): ?string
    {
        $state = self::str($cell['state'] ?? 'not-run');
        $prerelease = ($cell['prerelease'] ?? false) === true;
        if ($state === 'pass' && !$prerelease) {
            return null;
        }
        $who = self::str($framework['name']) . ' ' . self::str($framework['major']) . ' on PHP ' . self::str($cell['php_line'] ?? '');
        $versions = self::versions($cell);
        $text = match ($state) {
            'no' => $who . ': not run; ' . self::str($framework['package']) . ' ' . (is_array($framework['release'] ?? null) ? self::str($framework['release']['version']) : '') . ' requires php `' . self::str($framework['require_php'] ?? '') . '`.',
            'no-image' => $who . ': not run; no official `php` image for this line.',
            'not-run' => $who . ': not run yet.',
            'refused' => $who . $versions . ': Composer refused the platform. ' . self::str($cell['first_error'] ?? ''),
            'fail' => $who . $versions . ': failed at ' . self::str($cell['step'] ?? 'unknown') . '. ' . self::str($cell['first_error'] ?? '') . ' ' . self::messages($cell),
            'pass-deprecations' => $who . $versions . ': works, with ' . self::issueCount($cell) . '. ' . self::messages($cell),
            default => $who . $versions . ': passed.',
        };
        if ($prerelease) {
            $text .= ' PHP was a prerelease' . ($image !== null && $image['image'] !== null ? ' (`' . $image['image'] . '`)' : '') . '.';
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** @param array<string, mixed> $cell */
    private static function versions(array $cell): string
    {
        $bits = [];
        if (is_string($cell['php_version'] ?? null)) {
            $bits[] = 'PHP ' . $cell['php_version'];
        }
        $fw = is_array($cell['framework_package'] ?? null) ? $cell['framework_package'] : [];
        if (is_string($fw['version'] ?? null)) {
            $bits[] = self::str($fw['package'] ?? '') . ' ' . ltrim($fw['version'], 'v');
        }
        $sk = is_array($cell['skeleton'] ?? null) ? $cell['skeleton'] : [];
        if (is_string($sk['version'] ?? null)) {
            $bits[] = self::str($sk['package'] ?? '') . ' ' . ltrim($sk['version'], 'v');
        }

        return $bits === [] ? '' : ' (' . implode(', ', $bits) . ')';
    }

    /** @param array<string, mixed> $cell */
    private static function issueCount(array $cell): string
    {
        $d = is_array($cell['diagnostics'] ?? null) ? $cell['diagnostics'] : [];
        $dep = (int) self::str($d['deprecations'] ?? 0);
        $warn = (int) self::str($d['warnings'] ?? 0);
        $parts = [];
        if ($dep > 0) {
            $parts[] = $dep . ' deprecation' . ($dep === 1 ? '' : 's');
        }
        if ($warn > 0) {
            $parts[] = $warn . ' warning' . ($warn === 1 ? '' : 's');
        }

        return $parts === [] ? 'issues' : implode(' and ', $parts);
    }

    /** @param array<string, mixed> $cell */
    private static function messages(array $cell): string
    {
        $out = [];
        foreach (is_array($cell['messages'] ?? null) ? $cell['messages'] : [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $where = self::str($m['file'] ?? '') !== '' ? ' (' . self::str($m['file']) . ':' . self::str($m['line'] ?? '') . ', ' . self::str($m['step'] ?? '') . ')' : '';
            $out[] = '"' . self::str($m['message'] ?? '') . '"' . $where;
        }

        return $out === [] ? '' : 'First: ' . implode('; ', $out) . '.';
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
