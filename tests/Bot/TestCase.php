<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use stdClass;
use Waynestate\Creed\Bot\CommandRunner;
use Waynestate\Creed\Bot\PolicyBot;

/**
 * Shared setup for the policy robot tests: a throwaway repository root with
 * the data allowlist files, builders for refresher reports, and a capture
 * helper. No network, no real repository.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = sys_get_temp_dir() . '/creed-policy-bot-' . uniqid('', true);
        mkdir($this->repoRoot . '/policy', 0777, true);
        file_put_contents($this->repoRoot . '/policy/policy.json', "{}\n");
        file_put_contents($this->repoRoot . '/policy/sources.json', "{}\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->repoRoot . '/policy/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->repoRoot . '/policy');
        rmdir($this->repoRoot);
    }

    /**
     * Assemble the robot around a fake runner.
     *
     * @param list<int> $exits exit code for the nth refresher call
     * @param list<object|null> $reports report document for the nth refresher call, or null for none
     *
     * @return array{0: PolicyBot, 1: FakeRunner}
     */
    protected function makeBot(array $exits, array $reports = [], bool $dryRun = false): array
    {
        $fake = new FakeRunner($exits, $reports);

        return [
            new PolicyBot(
                repoRoot: $this->repoRoot,
                runner: new CommandRunner($fake(...)),
                dryRun: $dryRun,
            ),
            $fake,
        ];
    }

    /**
     * Run one bot action while capturing its echoed output.
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
