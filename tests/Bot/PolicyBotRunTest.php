<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Waynestate\Creed\Bot\CommandRunner;
use Waynestate\Creed\Bot\PolicyBot;

/**
 * The decision core: refresher exit code plus report in, decided outcome
 * out. Every test runs against a fake runner, so no real subprocess ever
 * starts except where a test says so.
 */
final class PolicyBotRunTest extends TestCase
{
    #[Test]
    public function aCleanRefresherMeansNothingToDo(): void
    {
        [$bot, $runner] = $this->makeBot([0], [$this->report()]);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('nothing to do', $out);
        self::assertCount(1, $runner->calls);
        self::assertStringNotContainsString('--offline', implode(' ', $runner->calls[0]));
        self::assertStringNotContainsString('--dry-run', implode(' ', $runner->calls[0]));
    }

    #[Test]
    public function theOfflineDirIsPassedThrough(): void
    {
        [$bot, $runner] = $this->makeBot([0], [$this->report()]);

        [$exit] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run('/fixtures/feeds'));

        self::assertSame(0, $exit);
        self::assertStringContainsString('--offline /fixtures/feeds', implode(' ', $runner->calls[0]));
    }

    #[Test]
    public function writtenChangesProduceTheCommitPlanAndMergeRequest(): void
    {
        [$bot, $runner] = $this->makeBot([3], [$this->report(changes: [$this->changeItem()])]);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString("changes written; the commit plan:\n  policy/policy.json\n  policy/sources.json\n", $out);
        self::assertStringContainsString('merge request title: policy: refresh from upstream sources', $out);
        self::assertStringContainsString('- php 8.3: latest_patch 8.3.35 -> 8.3.36 [neutral]', $out);
        self::assertCount(1, $runner->calls);
    }

    #[Test]
    public function heldChangesCommitNothing(): void
    {
        [$bot, $runner] = $this->makeBot([4], [$this->report(held: [$this->changeItem([
            'field' => 'security_until',
            'old' => '2026-12-31',
            'new' => '2027-12-31',
            'class' => 'loosen-fail',
            'reason' => 'upstream extended support past the policy; confirm before writing',
        ])])]);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('the refresher held or refused changes; nothing is committed', $out);
        self::assertStringContainsString('held php 8.3: security_until 2026-12-31 -> 2027-12-31 [loosen-fail]: upstream extended support past the policy; confirm before writing', $out);
        self::assertCount(1, $runner->calls);
    }

    #[Test]
    public function refusedChangesCommitNothing(): void
    {
        [$bot] = $this->makeBot([4], [$this->report(refused: [$this->changeItem([
            'component' => 'composer',
            'line' => '2.7',
            'field' => 'minimum',
            'old' => '2.7.0',
            'new' => null,
            'class' => 'refused',
            'reason' => 'composer 2.7 reached end of life on 2026-05-01; raise tools.composer.minimum',
        ])])]);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('refused composer 2.7: minimum 2.7.0 -> (removed) [refused]: composer 2.7 reached end of life on 2026-05-01; raise tools.composer.minimum', $out);
    }

    #[Test]
    public function aRefresherHardFailureStopsTheRobot(): void
    {
        [$bot, $runner] = $this->makeBot([1], []);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(1, $exit);
        self::assertStringContainsString('the refresher failed (exit 1)', $out);
        self::assertCount(1, $runner->calls);
    }

    #[Test]
    public function aRunnerStartupFailureStopsTheRobot(): void
    {
        $bot = new PolicyBot(
            repoRoot: $this->repoRoot,
            runner: new CommandRunner(static function (array $argv): array {
                throw new RuntimeException('could not start php');
            }),
        );

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(1, $exit);
        self::assertStringContainsString('the refresher could not start', $out);
    }

    #[Test]
    public function anUnknownRefresherExitCodeFails(): void
    {
        [$bot] = $this->makeBot([2], [$this->report()]);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(1, $exit);
        self::assertStringContainsString('unknown code (2)', $out);
    }

    #[Test]
    public function dryRunPrintsThePlanAndMergeRequest(): void
    {
        [$bot, $runner] = $this->makeBot([0], [$this->report(changes: [$this->changeItem()], dryRun: true)], dryRun: true);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString("dry run: would commit these files:\n  policy/policy.json\n  policy/sources.json\n", $out);
        self::assertStringContainsString('merge request title: policy: refresh from upstream sources', $out);
        self::assertStringContainsString('- php 8.3: latest_patch 8.3.35 -> 8.3.36 [neutral]', $out);
        self::assertStringContainsString('--dry-run', implode(' ', $runner->calls[0]));
        self::assertCount(1, $runner->calls);
    }

    #[Test]
    public function dryRunWithNoChangesSaysNothingToDo(): void
    {
        [$bot] = $this->makeBot([0], [$this->report(dryRun: true)], dryRun: true);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('dry run: nothing to do', $out);
    }

    #[Test]
    public function dryRunWithOnlyRefusalsStillPrintsTitleAndBody(): void
    {
        // The shape of the real offline fixture run: nothing to write, one
        // refusal and one disagreement on record.
        $report = $this->report(
            refused: [$this->changeItem([
                'component' => 'composer',
                'line' => '2.8',
                'field' => 'minimum',
                'old' => '2.8.0',
                'new' => null,
                'class' => 'refused',
                'reason' => 'composer 2.8 reached end of life on 2025-11-13; raise tools.composer.minimum',
            ])],
            disagreements: [$this->disagreementItem()],
            dryRun: true,
        );
        [$bot, $runner] = $this->makeBot([0], [$report], dryRun: true);

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('dry run: nothing to commit (1 refused, 1 disagreement)', $out);
        self::assertStringContainsString('refused composer 2.8: minimum 2.8.0 -> (removed) [refused]: composer 2.8 reached end of life on 2025-11-13; raise tools.composer.minimum', $out);
        self::assertStringContainsString('merge request title: policy: refresh from upstream sources', $out);
        self::assertStringContainsString('node 26 active_until: nodejs/schedule says 2027-10-20, endoflife.date/nodejs says 2027-10-27', $out);
        self::assertStringContainsString('--dry-run', implode(' ', $runner->calls[0]));
    }

    #[Test]
    public function theReportFileIsRemovedAfterTheRun(): void
    {
        [$bot, $runner] = $this->makeBot([0], [$this->report()]);

        $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        $index = array_search('--report', $runner->calls[0], true);
        self::assertIsInt($index);
        self::assertFileDoesNotExist($runner->calls[0][$index + 1]);
    }

    #[Test]
    public function theRefresherStdoutIsReplayed(): void
    {
        $bot = new PolicyBot(
            repoRoot: $this->repoRoot,
            runner: new CommandRunner(static fn(array $argv): array => [
                'status' => 0,
                'stdout' => "hello from the refresher\n",
                'stderr' => '',
            ]),
        );

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('hello from the refresher', $out);
    }
}
