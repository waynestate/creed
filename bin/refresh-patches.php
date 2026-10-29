<?php

declare(strict_types=1);

/**
 * Refreshes the latest_patch and latest_patch_checked fields of the PHP,
 * Laravel, and Node versions in policy/policy.json from the upstream feeds,
 * and proposes new Vue lines from endoflife.date.
 *
 * This is the original patch-only refresh. bin/refresh-policy.php is the
 * fuller tool: support dates, new lines for more components, provenance in
 * policy/sources.json, and held changes. This script keeps its own feeds,
 * messages, and exit codes for anyone already wiring it into a release step.
 *
 * Feeds:
 *   PHP         https://www.php.net/releases/active.php
 *   Laravel     https://repo.packagist.org/p2/laravel/framework.json
 *   Node        https://nodejs.org/dist/index.json
 *   Cross-check https://endoflife.date/api/v1/products/php/
 *   Vue lines   https://endoflife.date/api/v1/products/vue/
 *
 * Usage:
 *   php bin/refresh-patches.php [--dry-run] [--offline <dir>] [--policy <path>]
 *
 *   --dry-run        Print what would change without writing the policy.
 *   --offline <dir>  Read the feeds from <dir> instead of fetching them.
 *                    Expects php.json, laravel.json, node.json,
 *                    endoflife-php.json, and endoflife-vue.json in <dir>.
 *   --policy <path>  Path to the policy file (default: policy/policy.json).
 *
 * Exit codes:
 *   0  nothing to change (or --dry-run with nothing to change)
 *   3  the policy was updated (or --dry-run with changes to show)
 *   1  a feed could not be fetched or parsed
 *
 * The endoflife.date feeds are optional: the PHP one is a cross-check only,
 * and the Vue one proposes new lines. When either is missing or unparseable,
 * a warning is printed and the run continues, because php.net is the source
 * of record for PHP and a proposal is only a proposal.
 */

use Waynestate\Creed\Refresh\LegacyPatches;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/refresh-common.php';

$root = dirname(__DIR__);
$dryRun = false;
$offlineDir = null;
$policyPath = $root . '/policy/policy.json';

$args = array_slice($_SERVER['argv'] ?? [], 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--offline') {
        $i++;
        if ($i >= count($args)) {
            fwrite(STDERR, "--offline requires a directory\n");
            exit(1);
        }
        $offlineDir = $args[$i];
    } elseif ($arg === '--policy') {
        $i++;
        if ($i >= count($args)) {
            fwrite(STDERR, "--policy requires a path\n");
            exit(1);
        }
        $policyPath = $args[$i];
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}

$feeds = [
    'php' => ['file' => 'php.json', 'url' => 'https://www.php.net/releases/active.php'],
    'laravel' => ['file' => 'laravel.json', 'url' => 'https://repo.packagist.org/p2/laravel/framework.json'],
    'node' => ['file' => 'node.json', 'url' => 'https://nodejs.org/dist/index.json'],
    'endoflife-php' => ['file' => 'endoflife-php.json', 'url' => 'https://endoflife.date/api/v1/products/php/'],
    'endoflife-vue' => ['file' => 'endoflife-vue.json', 'url' => 'https://endoflife.date/api/v1/products/vue/'],
];

// The primary feeds are required; a failure here stops the run.
try {
    $phpJson = creedLoadFeed($feeds['php']['file'], $feeds['php']['url'], $offlineDir);
    $laravelJson = creedLoadFeed($feeds['laravel']['file'], $feeds['laravel']['url'], $offlineDir);
    $nodeJson = creedLoadFeed($feeds['node']['file'], $feeds['node']['url'], $offlineDir);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// The endoflife feeds are optional; a failure here is a warning.
$endOfLifePhpJson = null;
try {
    $endOfLifePhpJson = creedLoadFeed($feeds['endoflife-php']['file'], $feeds['endoflife-php']['url'], $offlineDir);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'warning: no endoflife.date PHP cross-check: ' . $e->getMessage() . "\n");
}
$endOfLifeVueJson = null;
try {
    $endOfLifeVueJson = creedLoadFeed($feeds['endoflife-vue']['file'], $feeds['endoflife-vue']['url'], $offlineDir);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'warning: no endoflife.date Vue feed: ' . $e->getMessage() . "\n");
}

exit((new LegacyPatches(
    $phpJson,
    $laravelJson,
    $nodeJson,
    $endOfLifePhpJson,
    $endOfLifeVueJson,
    $policyPath,
    $dryRun,
    date('Y-m-d'),
))->run());
