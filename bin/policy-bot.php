<?php

declare(strict_types=1);

/**
 * The CREED policy robot: it keeps policy/policy.json current with no
 * human in the loop. It runs bin/refresh-policy.php as a subprocess,
 * reads the run report, and decides the outcome: nothing to do, a commit
 * of the data allowlist that must pass the gate, or a hard failure. A
 * weekly heartbeat re-runs the refresher with --touch when refreshed_on
 * is 7 or more days old.
 *
 * The gate: the robot commits on the checked-out main commit and runs
 * composer ci in the same job. When that passes it pushes the commit
 * straight to main with CI_JOB_TOKEN (no merge request to merge by hand).
 * When it fails it pushes auto/policy-refresh as a merge request for
 * review instead and exits 1. A pipeline on any other branch never pushes.
 *
 * After a handled real run (a red gate and a failed push included) the
 * robot also brings its alert issues in line
 * with the report: one open GitLab issue per alert key (held, refused,
 * disagreement, upcoming end of life), labeled auto-alert. A key that is
 * still present gets a note; a key that is gone closes its issue. The
 * client is built from CI_API_V4_URL, CI_PROJECT_ID and CREED_BOT_TOKEN;
 * the bot token is for the alert issues only.
 *
 * Usage:
 *   php bin/policy-bot.php run [--dry-run] [--offline <dir>]
 *
 *   run  One scheduled refresh: run the refresher, commit the data
 *        allowlist and run composer ci. Green: push to main. Red: push
 *        auto/policy-refresh as a merge request. Either way (and after a
 *        failed push too) sync the alert issues. --dry-run prints the decided outcome, the
 *        planned files, the merge request title and body, and the alert
 *        issues it would open, and changes nothing. --offline <dir> reads
 *        the feeds from <dir> instead of fetching them
 *        (tests/fixtures/feeds/ carries a full set).
 *
 * Exit codes:
 *   run  0 on every handled outcome (nothing to do, a green push to main,
 *        commit plan, alert), 1 when the refresher hard-fails or cannot
 *        start, when the gate fails (the refresh went to a merge request),
 *        when the pipeline is not on the default branch, or when the
 *        alert environment (CI_API_V4_URL, CI_PROJECT_ID, CREED_BOT_TOKEN)
 *        is incomplete
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
