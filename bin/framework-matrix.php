<?php

declare(strict_types=1);

/**
 * The framework x PHP test matrix's plan and report. The containers in
 * between are run by scripts/framework-matrix.ps1 (Windows) and
 * scripts/framework-matrix.sh (Linux, macOS); each cell is
 * bin/framework-matrix-cell.php.
 *
 * Usage:
 *   php bin/framework-matrix.php plan [--out=<file>] [--offline=<dir>]
 *     Reads each framework's Packagist p2 feed (or <dir>/<vendor>--<name>.json),
 *     takes the newest release of every major in tools/FrameworkMatrix/frameworks.json,
 *     and writes the plan (default build/framework-matrix/plan.json). Prints one
 *     line per cell in run order: "<framework> <php line> run|no".
 *   php bin/framework-matrix.php report [--in=<dir>] [--images=<file>]
 *     Merges <dir>/plan.json and <dir>/cells/*.json (default build/framework-matrix)
 *     into data/framework-php-tests.json and rewrites the table in
 *     docs/guides/framework-php-tests.md. --images names a JSON file of
 *     line => {"image": ..., "prerelease": bool} or null for a line with no image.
 *
 * Needs PHP 8.3 or later and no Composer install. Exit 0 on success, 1 when a
 * feed or file could not be read, 2 on a usage error.
 */

$root = dirname(__DIR__);
require_once $root . '/deploy/lib/PhpLifecycle/autoload.php';
require_once $root . '/tools/FrameworkMatrix/Matrix.php';

use Waynestate\Creed\Site\PhpLifecycle\Http;
use Waynestate\Creed\Tools\FrameworkMatrix\Matrix;

$args = array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1);
$command = is_string($args[0] ?? null) ? $args[0] : '';
$options = [];
foreach (array_slice($args, 1) as $arg) {
    if (is_string($arg) && preg_match('/^--(out|offline|in|images)=(.+)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, 'framework-matrix: unknown argument ' . (is_string($arg) ? $arg : '') . ". Usage: php bin/framework-matrix.php plan|report [options]\n");
        exit(2);
    }
}

$config = Matrix::config($root . '/tools/FrameworkMatrix/frameworks.json');
$build = $root . '/build/framework-matrix';
$today = gmdate('Y-m-d');

if ($command === 'plan') {
    $packages = array_values(array_unique(array_map(static fn(array $f): string => $f['package'], $config['frameworks'])));
    $feeds = [];
    if (isset($options['offline'])) {
        foreach ($packages as $package) {
            $file = $options['offline'] . '/' . str_replace('/', '--', $package) . '.json';
            $feeds[$package] = is_file($file) ? (string) file_get_contents($file) : null;
        }
    } else {
        $urls = [];
        foreach ($packages as $package) {
            $urls[$package] = 'https://repo.packagist.org/p2/' . $package . '.json';
        }
        foreach ((new Http())->fetchPaced($urls, 1.0) as $package => $got) {
            $feeds[$package] = $got['status'] === 200 ? $got['body'] : null;
            if ($feeds[$package] === null) {
                fwrite(STDERR, "framework-matrix: could not read Packagist for {$package}: " . ($got['error'] ?? 'no body') . "\n");
            }
        }
    }
    if (in_array(null, $feeds, true)) {
        exit(1);
    }
    $plan = Matrix::plan($config, $feeds, $today);
    $out = $options['out'] ?? $build . '/plan.json';
    if (!is_dir(dirname($out))) {
        mkdir(dirname($out), 0777, true);
    }
    file_put_contents($out, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    foreach (Matrix::runList($plan) as $cell) {
        echo $cell['framework'], ' ', $cell['php_line'], ' ', $cell['run'] ? 'run' : 'no', "\n";
    }
    exit(0);
}

if ($command === 'report') {
    $in = $options['in'] ?? $build;
    $plan = json_decode((string) @file_get_contents($in . '/plan.json'), true);
    if (!is_array($plan) || !is_array($plan['cells'] ?? null)) {
        fwrite(STDERR, "framework-matrix: no plan at {$in}/plan.json; run the plan step first.\n");
        exit(1);
    }
    $results = [];
    foreach (glob($in . '/cells/*.json') ?: [] as $file) {
        $cell = json_decode((string) file_get_contents($file), true);
        if (is_array($cell)) {
            $results[] = $cell;
        }
    }
    $images = [];
    if (isset($options['images'])) {
        $decoded = json_decode((string) @file_get_contents($options['images']), true);
        $images = is_array($decoded) ? $decoded : [];
    }
    $dataFile = $root . '/data/framework-php-tests.json';
    $previous = is_file($dataFile) ? json_decode((string) file_get_contents($dataFile), true) : null;
    /** @var array{generated: string, php_lines: list<string>, frameworks: list<array<string, mixed>>, cells: list<array{framework: string, php_line: string, allowed: ?bool}>} $plan */
    /** @var array<string, array{image: ?string, prerelease: bool}|null> $images */
    $data = Matrix::merge($plan, $results, is_array($previous) ? $previous : null, $images, $today);
    if (!is_dir(dirname($dataFile))) {
        mkdir(dirname($dataFile), 0777, true);
    }
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $page = $root . '/docs/guides/framework-php-tests.md';
    file_put_contents($page, Matrix::replaceSection(is_file($page) ? (string) file_get_contents($page) : '', Matrix::markdown($data)));
    $counts = array_count_values(array_map(static fn(array $c): string => is_string($c['state'] ?? null) ? $c['state'] : 'not-run', $data['cells']));
    ksort($counts);
    echo 'framework-matrix: wrote data/framework-php-tests.json and docs/guides/framework-php-tests.md (', implode(', ', array_map(static fn(string $k, int $v): string => "{$k} {$v}", array_keys($counts), $counts)), ")\n";
    exit(0);
}

fwrite(STDERR, "framework-matrix: usage: php bin/framework-matrix.php plan [--out=<file>] [--offline=<dir>] | report [--in=<dir>] [--images=<file>]\n");
exit(2);
