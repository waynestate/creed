<?php

declare(strict_types=1);

/**
 * Refreshes policy/policy.json from the canonical upstream feeds: support
 * dates, latest patches, and new version lines, with provenance recorded in
 * policy/sources.json.
 *
 * Every change is classified before it is written:
 *   tighten        the line gets less support than before: written
 *   neutral        no change in what the policy allows: written
 *   loosen-warning the line gains support that cannot unlock a version:
 *                  written, but flagged in the output and the report
 *   loosen-fail    the line would become usable beyond what upstream
 *                  supports: held, unless a second source or the ledger
 *                  (the same value observed for 14 days including today)
 *                  agrees
 *   refused        never written automatically (for example, removing a
 *                  date, or an EOL composer minimum)
 *
 * Feeds (all optional at run time; a missing feed is a warning):
 *   PHP states      https://www.php.net/releases/states.php
 *   PHP active      https://www.php.net/releases/active.php
 *   Node schedule   https://raw.githubusercontent.com/nodejs/Release/main/schedule.json
 *   Node dist       https://nodejs.org/dist/index.json
 *   PHP cross-check https://endoflife.date/api/v1/products/php/
 *   Node cross-check https://endoflife.date/api/v1/products/nodejs/
 *   Laravel dates   https://endoflife.date/api/v1/products/laravel/
 *   Laravel patches https://repo.packagist.org/p2/laravel/framework.json
 *   MySQL lines     https://endoflife.date/api/v1/products/mysql/
 *   Vue lines       https://endoflife.date/api/v1/products/vue/
 *   Inertia tags    https://registry.npmjs.org/-/package/@inertiajs/vue3/dist-tags
 *   Composer lines  https://endoflife.date/api/v1/products/composer/
 *
 * Usage:
 *   php bin/refresh-policy.php [--dry-run] [--offline <dir>] [--policy <path>]
 *       [--sources <path>] [--report <path>] [--today YYYY-MM-DD] [--touch]
 *
 *   --dry-run        Print what would change without writing anything.
 *   --offline <dir>  Read the feeds from <dir> instead of fetching them.
 *                    Expects the feed files named after each source in
 *                    tests/fixtures/feeds/.
 *   --policy <path>  Path to the policy file (default: policy/policy.json).
 *   --sources <path> Path to the provenance ledger
 *                    (default: sources.json next to the policy).
 *   --report <path>  Also write a machine-readable report of the run.
 *   --today <date>   Pretend today is this date (deterministic runs).
 *   --touch          Set refreshed_on to today even when nothing else
 *                    changed: the robot's weekly heartbeat.
 *
 * Exit codes:
 *   0  nothing worth committing was written (and always 0 with --dry-run)
 *   1  a hard failure (unreadable policy, unwritable file, bad option)
 *   3  something was written and every written change is tighten, neutral
 *      or loosen-warning (a first sighting in policy/sources.json, or a
 *      --touch heartbeat, counts as something written)
 *   4  a change was held or refused, or a written change was a loosen-fail
 *      confirmed by a second source or by 14 stable days. Written changes
 *      are still written.
 */

use Waynestate\Creed\Refresh\EndOfLifeParser;
use Waynestate\Creed\Refresh\JsonFields;
use Waynestate\Creed\Refresh\NodeDistParser;
use Waynestate\Creed\Refresh\NodeScheduleParser;
use Waynestate\Creed\Refresh\NpmDistTagsParser;
use Waynestate\Creed\Refresh\PackagistP2Parser;
use Waynestate\Creed\Refresh\PhpActiveParser;
use Waynestate\Creed\Refresh\PhpStatesParser;
use Waynestate\Creed\Refresh\Refresher;
use Waynestate\Creed\Refresh\SourceLedger;
use Waynestate\Creed\Refresh\Sources;
use Waynestate\Creed\Refresh\StableJson;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/refresh-common.php';

$root = dirname(__DIR__);
$dryRun = false;
$offlineDir = null;
$policyPath = $root . '/policy/policy.json';
$sourcesPath = null;
$reportPath = null;
$today = date('Y-m-d');
$touch = false;

$args = array_slice($_SERVER['argv'] ?? [], 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--touch') {
        $touch = true;
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
    } elseif ($arg === '--sources') {
        $i++;
        if ($i >= count($args)) {
            fwrite(STDERR, "--sources requires a path\n");
            exit(1);
        }
        $sourcesPath = $args[$i];
    } elseif ($arg === '--report') {
        $i++;
        if ($i >= count($args)) {
            fwrite(STDERR, "--report requires a path\n");
            exit(1);
        }
        $reportPath = $args[$i];
    } elseif ($arg === '--today') {
        $i++;
        if ($i >= count($args)) {
            fwrite(STDERR, "--today requires a YYYY-MM-DD date\n");
            exit(1);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $args[$i]) !== 1) {
            fwrite(STDERR, "--today expects a YYYY-MM-DD date\n");
            exit(1);
        }
        $today = $args[$i];
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}
if ($sourcesPath === null) {
    $sourcesPath = dirname($policyPath) . '/sources.json';
}

// name => [offline file, url]. Every feed is optional: a failure is a
// reported warning, not a stopped run.
$feeds = [
    'php-states' => ['php-states.json', 'https://www.php.net/releases/states.php'],
    'php-active' => ['php.json', 'https://www.php.net/releases/active.php'],
    'node-schedule' => ['node-schedule.json', 'https://raw.githubusercontent.com/nodejs/Release/main/schedule.json'],
    'node-dist' => ['node.json', 'https://nodejs.org/dist/index.json'],
    'endoflife-php' => ['endoflife-php.json', 'https://endoflife.date/api/v1/products/php/'],
    'endoflife-nodejs' => ['endoflife-nodejs.json', 'https://endoflife.date/api/v1/products/nodejs/'],
    'endoflife-laravel' => ['endoflife-laravel.json', 'https://endoflife.date/api/v1/products/laravel/'],
    'packagist-laravel' => ['laravel.json', 'https://repo.packagist.org/p2/laravel/framework.json'],
    'endoflife-mysql' => ['endoflife-mysql.json', 'https://endoflife.date/api/v1/products/mysql/'],
    'endoflife-vue' => ['endoflife-vue.json', 'https://endoflife.date/api/v1/products/vue/'],
    'npm-inertia' => ['npm-inertia-vue3.json', 'https://registry.npmjs.org/-/package/@inertiajs/vue3/dist-tags'],
    'endoflife-composer' => ['endoflife-composer.json', 'https://endoflife.date/api/v1/products/composer/'],
];

/** @var list<string> $sourceErrors */
$sourceErrors = [];
$rawFeeds = [];
foreach ($feeds as $name => [$file, $url]) {
    try {
        $rawFeeds[$name] = creedLoadFeed($file, $url, $offlineDir);
    } catch (RuntimeException $e) {
        $sourceErrors[] = "{$name}: {$e->getMessage()}";
    }
}

/**
 * Parse one loaded feed, or record a source error and return null.
 *
 * @param callable(string): mixed $parser
 * @param list<string> $sourceErrors
 */
function parseFeed(string $name, ?string $feed, callable $parser, array &$sourceErrors): mixed
{
    if ($feed === null) {
        return null;
    }
    try {
        return $parser($feed);
    } catch (RuntimeException $e) {
        $sourceErrors[] = "{$name}: {$e->getMessage()}";
        return null;
    }
}

$phpStates = parseFeed('php-states', $rawFeeds['php-states'] ?? null, PhpStatesParser::parse(...), $sourceErrors);
$phpActive = parseFeed('php-active', $rawFeeds['php-active'] ?? null, PhpActiveParser::parse(...), $sourceErrors);
$nodeSchedule = parseFeed('node-schedule', $rawFeeds['node-schedule'] ?? null, NodeScheduleParser::parse(...), $sourceErrors);
$nodeDist = parseFeed('node-dist', $rawFeeds['node-dist'] ?? null, NodeDistParser::parse(...), $sourceErrors);
$endoflifePhp = parseFeed('endoflife-php', $rawFeeds['endoflife-php'] ?? null, EndOfLifeParser::parseReleases(...), $sourceErrors);
$endoflifeNodejs = parseFeed('endoflife-nodejs', $rawFeeds['endoflife-nodejs'] ?? null, EndOfLifeParser::parseReleases(...), $sourceErrors);
$endoflifeLaravel = parseFeed('endoflife-laravel', $rawFeeds['endoflife-laravel'] ?? null, EndOfLifeParser::parseReleases(...), $sourceErrors);
$packagistLaravel = parseFeed('packagist-laravel', $rawFeeds['packagist-laravel'] ?? null, PackagistP2Parser::parse(...), $sourceErrors);
$endoflifeMysql = parseFeed('endoflife-mysql', $rawFeeds['endoflife-mysql'] ?? null, EndOfLifeParser::parseReleases(...), $sourceErrors);
$endoflifeVue = parseFeed('endoflife-vue', $rawFeeds['endoflife-vue'] ?? null, EndOfLifeParser::parseReleases(...), $sourceErrors);
$npmInertia = parseFeed('npm-inertia', $rawFeeds['npm-inertia'] ?? null, NpmDistTagsParser::parse(...), $sourceErrors);
$endoflifeComposer = parseFeed('endoflife-composer', $rawFeeds['endoflife-composer'] ?? null, EndOfLifeParser::parseReleases(...), $sourceErrors);

$sources = new Sources(
    phpStates: $phpStates,
    phpActive: $phpActive,
    nodeSchedule: $nodeSchedule,
    nodeDist: $nodeDist,
    endoflifePhp: $endoflifePhp,
    endoflifeNodejs: $endoflifeNodejs,
    endoflifeLaravel: $endoflifeLaravel,
    packagistLaravel: $packagistLaravel,
    endoflifeMysql: $endoflifeMysql,
    endoflifeVue: $endoflifeVue,
    npmInertia: $npmInertia,
    endoflifeComposer: $endoflifeComposer,
    errors: $sourceErrors,
);

// The policy itself is not optional: without it there is nothing to refresh.
if (!is_file($policyPath)) {
    fwrite(STDERR, "Policy file not found: {$policyPath}\n");
    exit(1);
}
$policyRaw = file_get_contents($policyPath);
if ($policyRaw === false) {
    fwrite(STDERR, "Could not read policy file: {$policyPath}\n");
    exit(1);
}
$policy = json_decode($policyRaw, false);
if (!is_object($policy)) {
    fwrite(STDERR, "Policy file is not valid JSON: {$policyPath}\n");
    exit(1);
}
if (JsonFields::child($policy, 'components') === null) {
    fwrite(STDERR, "Policy has no \"components\" object\n");
    exit(1);
}

// The provenance ledger is history, not input: a broken one is reported and
// replaced, never guessed at.
$ledger = SourceLedger::empty();
if (is_file($sourcesPath)) {
    $ledgerRaw = file_get_contents($sourcesPath);
    if ($ledgerRaw === false) {
        $sourceErrors[] = "sources: could not read {$sourcesPath}; starting fresh";
    } else {
        $decoded = SourceLedger::decode($ledgerRaw);
        if ($decoded === null) {
            $sourceErrors[] = "sources: could not parse {$sourcesPath}; starting fresh";
        } else {
            $ledger = $decoded;
        }
    }
}

$refresher = new Refresher($today);
$result = $refresher->refresh($policy, $sources, $ledger);

foreach ($result->changes as $item) {
    echo ($dryRun ? 'would update ' : 'updated ') . $item->summary() . " [{$item->kind}]\n";
}
foreach ($result->held as $item) {
    echo "held {$item->summary()} [{$item->kind}]: {$item->reason}\n";
}
foreach ($result->refused as $item) {
    echo "refused {$item->component} {$item->line} {$item->field}: {$item->reason}\n";
}
foreach ($result->disagreements as $disagreement) {
    echo "warning: {$disagreement->summary()}\n";
}
foreach ($sourceErrors as $error) {
    echo "warning: {$error}\n";
}
if (!$result->anythingToReport() && $sourceErrors === []) {
    echo "No changes.\n";
}

$policyWritten = false;
$ledgerNews = false;
if (!$dryRun) {
    $currentOn = JsonFields::str($policy, 'refreshed_on');
    if ($result->changes !== [] || ($touch && $currentOn !== $today)) {
        $encoded = StableJson::encode($refresher->withRefreshedOn($policy)) . "
";
        if (file_put_contents($policyPath, $encoded) === false) {
            fwrite(STDERR, "Could not write policy file: {$policyPath}
");
            exit(1);
        }
        $policyWritten = true;
    }
    // The ledger file always follows the run (last_seen moves), but only a
    // new or changed value is news worth a commit.
    $ledgerEncoded = StableJson::encode($ledger->toObject()) . "
";
    $existingLedger = is_file($sourcesPath) ? (string) file_get_contents($sourcesPath) : null;
    if ($ledger->hasObservations() && $ledgerEncoded !== $existingLedger) {
        if (file_put_contents($sourcesPath, $ledgerEncoded) === false) {
            fwrite(STDERR, "Could not write sources file: {$sourcesPath}
");
            exit(1);
        }
        $ledgerNews = $ledger->hasMaterialChange();
    }
}

if ($reportPath !== null) {
    $encoded = StableJson::encode($result->reportObject($today, $dryRun, $sourceErrors)) . "
";
    if (file_put_contents($reportPath, $encoded) === false) {
        fwrite(STDERR, "Could not write report file: {$reportPath}
");
        exit(1);
    }
}

if ($dryRun) {
    exit(0);
}
if ($result->held !== [] || $result->refused !== [] || $result->hasLoosenFailChanges()) {
    exit(4);
}
exit($policyWritten || $ledgerNews ? 3 : 0);
