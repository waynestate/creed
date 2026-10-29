<?php

declare(strict_types=1);

/**
 * The CREED policy robot: it keeps policy/policy.json current with no
 * human in the loop. It runs bin/refresh-policy.php as a subprocess,
 * reads the run report, and decides the outcome: nothing to do, a commit
 * of the data allowlist pushed as a merge request (auto-merged on exit 3),
 * or a hard failure. A weekly heartbeat re-runs the refresher with
 * --touch when refreshed_on is 7 or more days old.
 *
 * Usage:
 *   php bin/policy-bot.php run [--dry-run] [--offline <dir>]
 *
 *   run  One scheduled refresh: run the refresher, then commit the data
 *        allowlist on auto/policy-refresh and push it with merge request
 *        options. --dry-run prints the decided outcome, the planned
 *        files, the merge request title and body, and changes nothing.
 *        --offline <dir> reads the feeds from <dir> instead of fetching
 *        them (tests/fixtures/feeds/ carries a full set).
 *
 * Exit codes:
 *   run  0 on every handled outcome (nothing to do, commit plan, alert),
 *        1 when the refresher hard-fails or cannot start
 */

use Waynestate\Creed\Bot\CommandRunner;
use Waynestate\Creed\Bot\PolicyBot;

require dirname(__DIR__) . '/vendor/autoload.php';

const USAGE = "Usage: php bin/policy-bot.php run [--dry-run] [--offline <dir>]\n";

$args = array_slice($_SERVER['argv'] ?? [], 1);
$command = isset($args[0]) ? (string) $args[0] : null;
$dryRun = false;
$offlineDir = null;

for ($i = 1, $count = count($args); $i < $count; $i++) {
    $arg = (string) $args[$i];
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--offline') {
        $i++;
        if ($i >= $count) {
            fwrite(STDERR, "--offline requires a directory\n");
            exit(1);
        }
        $offlineDir = (string) $args[$i];
    } elseif ($arg === '--help' || $arg === '-h') {
        echo USAGE;
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n" . USAGE);
        exit(1);
    }
}

if ($command !== 'run') {
    fwrite(STDERR, $command === null ? USAGE : "Unknown command: {$command}\n" . USAGE);
    exit(1);
}

$bot = new PolicyBot(
    repoRoot: dirname(__DIR__),
    runner: new CommandRunner(),
    dryRun: $dryRun,
);

exit($bot->run($offlineDir));
