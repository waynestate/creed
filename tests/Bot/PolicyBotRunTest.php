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
        self::assertStringNotContainsString('--touch', implode(' ', $runner->calls[0]));
        self::assertSame([], $this->gitCalls($runner));
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
    public function writtenChangesCommitAndPushWithAutoMerge(): void
    {
        $this->ciEnvironment();
        [$bot, $runner] = $this->makeBot([3], [$this->report(changes: [$this->changeItem()])]);
        $runner->git['diff'] = ['status' => 0, 'stdout' => "policy/policy.json\n", 'stderr' => ''];

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString("changes written; the commit plan:\n  policy/policy.json\n  policy/sources.json\n", $out);
        self::assertStringContainsString('- php 8.3: latest_patch 8.3.35 -> 8.3.36 [neutral]', $out);
        self::assertStringContainsString('merge request title: policy: refresh from upstream sources', $out);

        $git = $this->gitCalls($runner);
        self::assertSame(['checkout', '-B', 'auto/policy-refresh'], $git['checkout']);
        self::assertSame(['add', 'policy/policy.json', 'policy/sources.json'], $git['add']);
        self::assertSame(
            ['-c', 'user.name=CREED policy bot', '-c', 'user.email=policy-bot@noreply.invalid', 'commit', '-m', 'policy: refresh from upstream sources'],
            $git['commit'],
        );
        $push = $git['push'];
        self::assertSame(['push', '--force'], array_slice($push, 0, 2));
        self::assertSame(
            [
                'merge_request.create',
                'merge_request.target=main',
                'merge_request.remove_source_branch',
                'merge_request.label=auto-data',
                'merge_request.title=policy: refresh from upstream sources',
                'merge_request.auto_merge',
            ],
            $this->pushOptions($push),
        );
        self::assertSame('https://oauth2:test-token-123@git.example.com:443/waynestate/creed.git', $push[count($push) - 2]);
        self::assertSame('HEAD:refs/heads/auto/policy-refresh', $push[count($push) - 1]);
    }

    #[Test]
    public function heldChangesPushWithoutAutoMerge(): void
    {
        $this->ciEnvironment();
        [$bot, $runner] = $this->makeBot([4], [$this->report(held: [$this->changeItem([
            'field' => 'security_until',
            'old' => '2026-12-31',
            'new' => '2027-12-31',
            'class' => 'loosen-fail',
            'reason' => 'upstream extended support past the policy; confirm before writing',
        ])])]);
        $runner->git['diff'] = ['status' => 0, 'stdout' => "policy/policy.json\n", 'stderr' => ''];

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('the refresher held or refused some changes; committing what was written', $out);
        self::assertStringContainsString('held php 8.3: security_until 2026-12-31 -> 2027-12-31 [loosen-fail]: upstream extended support past the policy; confirm before writing', $out);

        $git = $this->gitCalls($runner);
        self::assertSame(['checkout', '-B', 'auto/policy-refresh'], $git['checkout']);
        self::assertArrayHasKey('commit', $git);
        self::assertArrayHasKey('push', $git);
        self::assertContains('merge_request.create', $this->pushOptions($git['push']));
        self::assertNotContains('merge_request.auto_merge', $this->pushOptions($git['push']));
    }

    #[Test]
    public function refusedChangesCommitNothing(): void
    {
        $this->ciEnvironment();
        [$bot, $runner] = $this->makeBot([4], [$this->report(refused: [$this->changeItem([
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
        self::assertStringContainsString('nothing staged; skipping the commit and push', $out);

        $git = $this->gitCalls($runner);
        self::assertArrayNotHasKey('commit', $git);
        self::assertArrayNotHasKey('push', $git);
    }

    #[Test]
    public function aStaleHeartbeatRunsTheTouchRefresher(): void
    {
        // The fixture stamps policy.json with today; age the stamp 7 days.
        file_put_contents($this->repoRoot . '/policy/policy.json', json_encode(['refreshed_on' => '2026-10-22']) . "\n");
        $this->ciEnvironment();
        [$bot, $runner] = $this->makeBot([0, 3], [$this->report(), $this->report(changes: [$this->changeItem()])], today: '2026-10-29');
        $runner->git['diff'] = ['status' => 0, 'stdout' => "policy/policy.json\n", 'stderr' => ''];

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('the last refresh was 7 days old; running the refresher with --touch', $out);

        $refresher = [];
        foreach ($runner->calls as $argv) {
            if (str_contains(implode(' ', $argv), 'refresh-policy.php')) {
                $refresher[] = implode(' ', $argv);
            }
        }
        self::assertCount(2, $refresher);
        self::assertStringNotContainsString('--touch', $refresher[0]);
        self::assertStringContainsString('--touch', $refresher[1]);
        self::assertArrayHasKey('push', $this->gitCalls($runner));
    }

    #[Test]
    public function aSixDayOldRefreshDoesNotTouch(): void
    {
        file_put_contents($this->repoRoot . '/policy/policy.json', json_encode(['refreshed_on' => '2026-10-23']) . "\n");
        [$bot, $runner] = $this->makeBot([0], [$this->report()], today: '2026-10-29');

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('nothing to do', $out);
        self::assertCount(1, $runner->calls);
        self::assertStringNotContainsString('--touch', implode(' ', $runner->calls[0]));
    }

    #[Test]
    public function aMissingCiServerHostExitsOneNamingIt(): void
    {
        $this->ciEnvironment(['CI_SERVER_HOST' => '']);
        [$bot, $runner] = $this->makeBot([3], [$this->report(changes: [$this->changeItem()])]);
        $runner->git['diff'] = ['status' => 0, 'stdout' => "policy/policy.json\n", 'stderr' => ''];

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('missing environment variables: CI_SERVER_HOST', $out);
        self::assertSame([], $this->gitCalls($runner));
    }

    #[Test]
    public function thePushTokenIsMaskedInEveryLine(): void
    {
        // A fake runner that echoes the push URL back in an error; the
        // printed output must not contain the token.
        $token = 'super-secret-push-token';
        $url = "https://oauth2:{$token}@git.example.com:443/waynestate/creed.git";
        $this->ciEnvironment(['CREED_BOT_TOKEN' => $token]);
        $fake = new FakeRunner([3], [$this->report(changes: [$this->changeItem()])]);
        $fake->git['diff'] = ['status' => 0, 'stdout' => "policy/policy.json\n", 'stderr' => ''];
        $fake->git['push'] = [
            'status' => 0,
            'stdout' => '',
            'stderr' => "fatal: unable to access '{$url}': The requested URL returned error: 403\n",
        ];
        $errorStream = fopen('php://memory', 'w+');
        if ($errorStream === false) {
            self::fail('could not open a memory stream for stderr');
        }
        $bot = new PolicyBot(
            repoRoot: $this->repoRoot,
            runner: new CommandRunner($fake(...)),
            errorStream: $errorStream,
            alerts: $this->makeAlerts(new FakeTransport()),
        );

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());
        rewind($errorStream);
        $errOut = (string) stream_get_contents($errorStream);
        fclose($errorStream);
        $printed = $out . $errOut;

        self::assertSame(0, $exit, $printed);
        self::assertStringNotContainsString($token, $printed);
        self::assertStringContainsString('[masked]', $printed);
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
            alerts: $this->makeAlerts(new FakeTransport()),
        );

        [$exit, $out] = $this->capture($bot, static fn(PolicyBot $b): int => $b->run());

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('hello from the refresher', $out);
    }
}
