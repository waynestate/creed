<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use stdClass;
use Waynestate\Creed\Bot\CommandRunner;
use Waynestate\Creed\Bot\PolicyBot;

/**
 * Shared setup for the policy robot tests: a throwaway repository root with
 * the data allowlist files (policy.json stamped with today, so the weekly
 * heartbeat stays quiet unless a test ages it), builders for refresher
 * reports, and a capture helper for echoed stdout. No network, no real
 * repository.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /**
     * The environment variables the tests set, cleared in tearDown.
     *
     * @var list<string>
     */
    private const CI_ENVIRONMENT = [
        'CI_SERVER_PROTOCOL',
        'CI_SERVER_HOST',
        'CI_SERVER_PORT',
        'CI_PROJECT_PATH',
        'CI_DEFAULT_BRANCH',
        'CREED_BOT_TOKEN',
    ];

    protected string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = sys_get_temp_dir() . '/creed-policy-bot-' . uniqid('', true);
        mkdir($this->repoRoot . '/policy', 0777, true);
        file_put_contents($this->repoRoot . '/policy/policy.json', json_encode(['refreshed_on' => date('Y-m-d')]) . "\n");
        file_put_contents($this->repoRoot . '/policy/sources.json', "{}\n");
    }

    protected function tearDown(): void
    {
        foreach (self::CI_ENVIRONMENT as $name) {
            putenv($name);
        }
        foreach (glob($this->repoRoot . '/policy/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->repoRoot . '/policy');
        rmdir($this->repoRoot);
    }

    /**
     * Set the CI environment a push needs, with optional overrides. An
     * override to '' simulates a missing variable. tearDown restores all of
     * it.
     *
     * @param array<string, string> $overrides
     */
    protected function ciEnvironment(array $overrides = []): void
    {
        $values = array_merge(
            [
                'CI_SERVER_PROTOCOL' => 'https',
                'CI_SERVER_HOST' => 'git.example.com',
                'CI_SERVER_PORT' => '443',
                'CI_PROJECT_PATH' => 'waynestate/creed',
                'CI_DEFAULT_BRANCH' => 'main',
                'CREED_BOT_TOKEN' => 'test-token-123',
            ],
            $overrides,
        );
        foreach ($values as $name => $value) {
            putenv("{$name}={$value}");
        }
    }

    /**
     * Assemble the robot around a fake runner.
     *
     * @param list<int> $exits exit code for the nth refresher call
     * @param list<object|null> $reports report document for the nth refresher call, or null for none
     * @param string|null $today YYYY-MM-DD for the heartbeat, the real today by default
     *
     * @return array{0: PolicyBot, 1: FakeRunner}
     */
    protected function makeBot(array $exits, array $reports = [], bool $dryRun = false, ?string $today = null): array
    {
        $fake = new FakeRunner($exits, $reports);

        return [
            new PolicyBot(
                repoRoot: $this->repoRoot,
                runner: new CommandRunner($fake(...)),
                dryRun: $dryRun,
                today: $today,
            ),
            $fake,
        ];
    }

    /**
     * Run one bot action while capturing its echoed stdout. Error lines go
     * to the bot's error stream; tests that need them inject one.
     *
     * @param callable(PolicyBot): int $action
     *
     * @return array{0: int, 1: string} the exit code and the captured output
     */
    protected function capture(PolicyBot $bot, callable $action): array
    {
        ob_start();
        try {
            $exit = $action($bot);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$exit, $out];
    }

    /**
     * The recorded git calls, keyed by subcommand (the first argument that
     * is not an option, so "git -c x=y commit" keys under commit), argv
     * without the leading "git".
     *
     * @return array<string, list<string>>
     */
    protected function gitCalls(FakeRunner $runner): array
    {
        $calls = [];
        foreach ($runner->calls as $argv) {
            if (($argv[0] ?? '') !== 'git') {
                continue;
            }
            $sub = '';
            for ($i = 1, $count = count($argv); $i < $count; $i++) {
                // "-c" consumes its value, which is not an option itself.
                if ($argv[$i] === '-c') {
                    $i++;
                    continue;
                }
                if (!str_starts_with($argv[$i], '-')) {
                    $sub = $argv[$i];
                    break;
                }
            }
            $calls[$sub] = array_slice($argv, 1);
        }

        return $calls;
    }

    /**
     * The -o option values out of a recorded push argv.
     *
     * @param list<string> $push
     *
     * @return list<string>
     */
    protected function pushOptions(array $push): array
    {
        $options = [];
        foreach ($push as $i => $arg) {
            if ($arg === '-o' && isset($push[$i + 1])) {
                $options[] = $push[$i + 1];
            }
        }

        return $options;
    }

    /**
     * One report item, the way RefreshResult::reportObject writes it.
     *
     * @param array<string, mixed> $overrides
     */
    protected function changeItem(array $overrides = []): stdClass
    {
        $item = new stdClass();
        $item->component = 'php';
        $item->line = '8.3';
        $item->field = 'latest_patch';
        $item->old = '8.3.35';
        $item->new = '8.3.36';
        $item->class = 'neutral';
        $item->sources = ['php-active'];
        $item->reason = '';
        foreach ($overrides as $key => $value) {
            $item->{$key} = $value;
        }

        return $item;
    }

    /**
     * One disagreement, the way RefreshResult::reportObject writes it.
     *
     * @param array<string, mixed> $overrides
     */
    protected function disagreementItem(array $overrides = []): stdClass
    {
        $item = new stdClass();
        $item->component = 'node';
        $item->line = '26';
        $item->field = 'active_until';
        $item->primary_source = 'nodejs/schedule';
        $item->primary_value = '2027-10-20';
        $item->secondary_source = 'endoflife.date/nodejs';
        $item->secondary_value = '2027-10-27';
        foreach ($overrides as $key => $value) {
            $item->{$key} = $value;
        }

        return $item;
    }

    /**
     * A full --report document.
     *
     * @param list<stdClass> $changes
     * @param list<stdClass> $held
     * @param list<stdClass> $refused
     * @param list<stdClass> $disagreements
     * @param list<string> $sourceErrors
     */
    protected function report(
        array $changes = [],
        array $held = [],
        array $refused = [],
        array $disagreements = [],
        array $sourceErrors = [],
        string $generatedOn = '2026-10-29',
        bool $dryRun = false,
    ): stdClass {
        $root = new stdClass();
        $root->generated_on = $generatedOn;
        $root->dry_run = $dryRun;
        $root->changes = $changes;
        $root->held = $held;
        $root->refused = $refused;
        $root->disagreements = $disagreements;
        $root->source_errors = $sourceErrors;

        return $root;
    }
}
