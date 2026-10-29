<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Bot;

use PHPUnit\Framework\Attributes\Test;
use Waynestate\Creed\Bot\AlertIssues;
use Waynestate\Creed\Bot\GitLabClient;

/**
 * The alert channel: which keys a report raises, what a dry run would open,
 * and how sync() opens, notes and closes the matching issues. The policy
 * document is the fixture file, rewritten per test where a test cares.
 */
final class AlertIssuesTest extends TestCase
{
    private const TODAY = '2026-10-29';

    /**
     * Replace the fixture policy document with one a test controls.
     *
     * @param array<string, mixed> $doc
     */
    private function writePolicy(array $doc): void
    {
        file_put_contents($this->repoRoot . '/policy/policy.json', json_encode($doc) . "\n");
    }

    #[Test]
    public function planListsOneOpenIssuePerCurrentKey(): void
    {
        $report = $this->report(
            held: [$this->changeItem(['field' => 'security_until', 'old' => '2026-12-31', 'new' => '2027-12-31', 'class' => 'loosen-fail'])],
            refused: [$this->changeItem(['component' => 'composer', 'line' => '2.7', 'field' => 'minimum', 'old' => '2.7.0', 'new' => null, 'class' => 'refused'])],
            disagreements: [$this->disagreementItem()],
        );
        $alerts = $this->makeAlerts(new FakeTransport(), self::TODAY);

        $plan = $alerts->plan($report);

        $titles = array_column($plan, 'title');
        sort($titles);
        self::assertSame(
            [
                '[auto-alert] disagreement:node:26:active_until',
                '[auto-alert] held:php:8.3:security_until',
                '[auto-alert] refused:composer:2.7:minimum',
            ],
            $titles,
        );
        foreach ($plan as $item) {
            self::assertSame('open', $item['action']);
            self::assertStringContainsString('First seen on ' . self::TODAY, $item['body']);
        }
    }

    #[Test]
    public function planIsEmptyForACleanReport(): void
    {
        $alerts = $this->makeAlerts(new FakeTransport(), self::TODAY);

        self::assertSame([], $alerts->plan($this->report()));
    }

    #[Test]
    public function planMakesNoTransportCall(): void
    {
        $transport = new FakeTransport();
        $alerts = $this->makeAlerts($transport, self::TODAY);

        $alerts->plan($this->report(held: [$this->changeItem()]));

        self::assertSame([], $transport->calls);
    }

    #[Test]
    public function aDisagreementPolicyAlreadySidesWithIsSuppressed(): void
    {
        // policy holds the primary value, so the robot stays quiet.
        $this->writePolicy(['components' => ['node' => ['versions' => ['26' => ['active_until' => '2027-10-20']]]]]);
        $alerts = $this->makeAlerts(new FakeTransport(), self::TODAY);

        self::assertSame([], $alerts->currentKeys($this->report(disagreements: [$this->disagreementItem()])));
    }

    #[Test]
    public function aDisagreementPolicySidesWithNeitherIsKept(): void
    {
        // policy holds a third value, so the disagreement stands.
        $this->writePolicy(['components' => ['node' => ['versions' => ['26' => ['active_until' => '2027-12-31']]]]]);
        $alerts = $this->makeAlerts(new FakeTransport(), self::TODAY);

        $keys = $alerts->currentKeys($this->report(disagreements: [$this->disagreementItem()]));

        self::assertArrayHasKey('disagreement:node:26:active_until', $keys);
    }

    #[Test]
    public function upcomingEolFiresWithinSixtyDays(): void
    {
        // 2026-10-29 + 30 days = 2026-11-28.
        $this->writePolicy(['components' => ['php' => ['versions' => ['8.3' => ['security_until' => '2026-11-28']]]]]);
        $alerts = $this->makeAlerts(new FakeTransport(), self::TODAY);

        $keys = $alerts->currentKeys($this->report());

        self::assertArrayHasKey('upcoming-eol:php:8.3', $keys);
    }

    #[Test]
    public function upcomingEolStaysSilentPastSixtyDays(): void
    {
        // 2026-10-29 + 90 days = 2027-01-27.
        $this->writePolicy(['components' => ['php' => ['versions' => ['8.3' => ['security_until' => '2027-01-27']]]]]);
        $alerts = $this->makeAlerts(new FakeTransport(), self::TODAY);

        self::assertSame([], $alerts->currentKeys($this->report()));
    }

    #[Test]
    public function upcomingEolStaysSilentWhenAlreadyPast(): void
    {
        $this->writePolicy(['components' => ['php' => ['versions' => ['8.3' => ['security_until' => '2026-10-01']]]]]);
        $alerts = $this->makeAlerts(new FakeTransport(), self::TODAY);

        self::assertSame([], $alerts->currentKeys($this->report()));
    }

    #[Test]
    public function syncOpensANewIssueForEachNewKey(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => []],
            ['status' => 201, 'body' => ['iid' => 11]],
        ]);
        $alerts = $this->makeAlerts($transport, self::TODAY);
        $report = $this->report(held: [$this->changeItem(['field' => 'security_until', 'old' => '2026-12-31', 'new' => '2027-12-31', 'class' => 'loosen-fail'])]);

        [$exit, $errors, $actions] = $alerts->sync($report);

        self::assertSame(0, $exit);
        self::assertSame([], $errors);
        self::assertSame(['opened [auto-alert] held:php:8.3:security_until'], $actions);
        self::assertSame(['GET', 'POST'], array_column($transport->calls, 'method'));
        self::assertStringEndsWith('/projects/42/issues', $transport->calls[1]['url']);
    }

    #[Test]
    public function syncNotesAnExistingIssueWhoseKeyIsStillPresent(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => [['title' => '[auto-alert] held:php:8.3:security_until', 'iid' => 7]]],
            ['status' => 201, 'body' => ['id' => 5]],
        ]);
        $alerts = $this->makeAlerts($transport, self::TODAY);
        $report = $this->report(held: [$this->changeItem(['field' => 'security_until', 'old' => '2026-12-31', 'new' => '2027-12-31', 'class' => 'loosen-fail'])]);

        [$exit, $errors, $actions] = $alerts->sync($report);

        self::assertSame(0, $exit);
        self::assertSame([], $errors);
        self::assertSame(['noted [auto-alert] held:php:8.3:security_until (issue #7)'], $actions);
        self::assertSame(['GET', 'POST'], array_column($transport->calls, 'method'));
        self::assertStringEndsWith('/projects/42/issues/7/notes', $transport->calls[1]['url']);
        $noteForm = $transport->calls[1]['form'];
        self::assertIsArray($noteForm);
        self::assertStringStartsWith('Still present on ' . self::TODAY . '.', $noteForm['body']);
    }

    #[Test]
    public function syncClosesAnIssueWhoseKeyIsGone(): void
    {
        $transport = new FakeTransport([
            ['status' => 200, 'body' => [['title' => '[auto-alert] held:php:8.3:security_until', 'iid' => 9]]],
            ['status' => 201, 'body' => ['id' => 6]],
            ['status' => 200, 'body' => ['iid' => 9, 'state' => 'closed']],
        ]);
        $alerts = $this->makeAlerts($transport, self::TODAY);

        [$exit, $errors, $actions] = $alerts->sync($this->report());

        self::assertSame(0, $exit);
        self::assertSame([], $errors);
        self::assertSame(['closed [auto-alert] held:php:8.3:security_until (issue #9)'], $actions);
        self::assertSame(['GET', 'POST', 'PUT'], array_column($transport->calls, 'method'));
        self::assertSame(['body' => 'Cleared on ' . self::TODAY . '.'], $transport->calls[1]['form']);
        self::assertSame(['state_event' => 'close'], $transport->calls[2]['form']);
        self::assertStringEndsWith('/projects/42/issues/9', $transport->calls[2]['url']);
    }

    #[Test]
    public function syncReturnsOneWhenACallFails(): void
    {
        $token = 'top-secret-token';
        $transport = new FakeTransport([
            ['status' => 200, 'body' => []],
            ['status' => 500, 'body' => "boom {$token}"],
        ]);
        $client = new GitLabClient($transport, 'https://git.example.com/api/v4', '42', $token);
        $alerts = new AlertIssues($client, $this->repoRoot . '/policy/policy.json', self::TODAY);
        $report = $this->report(held: [$this->changeItem(['field' => 'security_until', 'old' => '2026-12-31', 'new' => '2027-12-31', 'class' => 'loosen-fail'])]);

        [$exit, $errors, $actions] = $alerts->sync($report);

        self::assertSame(1, $exit);
        self::assertCount(1, $errors);
        self::assertSame([], $actions);
        self::assertStringStartsWith('alert failed for held:php:8.3:security_until:', $errors[0]);
        self::assertStringNotContainsString($token, $errors[0]);
        self::assertStringContainsString('[masked]', $errors[0]);
    }
}
