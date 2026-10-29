<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Refresh;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Refresh\Refresher;
use Waynestate\Creed\Refresh\RefreshResult;
use Waynestate\Creed\Refresh\Release;
use Waynestate\Creed\Refresh\SourceLedger;
use Waynestate\Creed\Refresh\SourceNames;
use Waynestate\Creed\Refresh\Sources;
use Waynestate\Creed\Refresh\StableJson;

/**
 * The refresh engine on small in-memory policies and hand-built feeds. No
 * network, no files.
 */
final class RefresherTest extends TestCase
{
    private const TODAY = '2026-10-29';

    private const POLICY = <<<'JSON'
{
    "schema_version": 1,
    "components": {
        "php": {"versions": {
            "8.3": {"active_until": "2025-12-31", "security_until": "2027-12-31", "latest_patch": "8.3.1", "latest_patch_checked": "2026-10-01"},
            "8.4": {"active_until": "2026-12-31", "security_until": "2028-12-31"}
        }},
        "laravel": {"versions": {
            "13": {"active_until": "2027-09-17", "security_until": "2028-03-17"}
        }},
        "node": {"versions": {
            "24": {"active_until": "2026-10-20", "security_until": "2028-04-30"}
        }},
        "mysql": {"versions": {
            "9.7": {"active_until": "2031-04-30", "security_until": "2034-04-30"}
        }},
        "vue": {"versions": {
            "2": {"active_until": "2023-12-31", "security_until": "2023-12-31"},
            "3": {"current": true}
        }},
        "inertia": {"versions": {
            "2": {"current": true}
        }}
    },
    "tools": {"composer": {"minimum": "2.8.0"}},
    "rules": {"eol_grace_days": 90}
}
JSON;

    private function policy(): object
    {
        $policy = json_decode(self::POLICY, false);

        return $policy;
    }

    private function release(string $name, ?string $eoas = null, ?string $eol = null, bool $isEol = false, ?bool $lts = null, ?string $latest = null): Release
    {
        return new Release($name, $isEol, $eol, $eoas, $lts, $latest);
    }

    /**
     * @return array{0: RefreshResult, 1: object}
     */
    private function runWith(Sources $sources, ?SourceLedger $ledger = null, string $today = self::TODAY): array
    {
        $policy = $this->policy();
        $result = (new Refresher($today))->refresh($policy, $sources, $ledger ?? SourceLedger::empty());

        return [$result, $policy];
    }

    private function field(object $policy, string $component, string $line, string $field): mixed
    {
        return $policy->components->{$component}->versions->{$line}->{$field} ?? null;
    }

    /** @return array<string, array{state: ?string, active_until: ?string, security_until: ?string}> */
    private function states(string $security84): array
    {
        return ['8.4' => ['state' => 'stable', 'active_until' => '2026-12-31', 'security_until' => $security84]];
    }

    #[Test]
    public function aLaterSecurityDateFromOneSourceIsHeldAndNotWritten(): void
    {
        $sources = new Sources(
            phpStates: $this->states('2029-12-31'),
            endoflifePhp: [$this->release('8.4', '2026-12-31', '2028-12-31')],
        );
        [$result, $policy] = $this->runWith($sources);

        self::assertCount(1, $result->held);
        self::assertSame('loosen-fail', $result->held[0]->kind);
        self::assertSame([], $result->changes);
        self::assertSame('2028-12-31', $this->field($policy, 'php', '8.4', 'security_until'));
    }

    #[Test]
    public function theSameChangeIsWrittenWhenTwoSourcesAgree(): void
    {
        $sources = new Sources(
            phpStates: $this->states('2029-12-31'),
            endoflifePhp: [$this->release('8.4', '2026-12-31', '2029-12-31')],
        );
        [$result, $policy] = $this->runWith($sources);

        self::assertSame([], $result->held);
        self::assertCount(1, $result->changes);
        self::assertSame('loosen-fail', $result->changes[0]->kind);
        self::assertSame([SourceNames::EOL_PHP], $result->changes[0]->agreement);
        self::assertSame('2029-12-31', $this->field($policy, 'php', '8.4', 'security_until'));
    }

    #[Test]
    public function theSameChangeIsWrittenAfterFourteenStableDaysInTheLedger(): void
    {
        $sources = new Sources(
            endoflifeLaravel: [$this->release('13', '2027-09-17', '2028-09-17')],
        );

        $young = SourceLedger::empty();
        $young->record('laravel', '13', 'security_until', SourceNames::EOL_LARAVEL, '2028-09-17', '2026-10-20');
        [$held] = $this->runWith($sources, $young);
        self::assertCount(1, $held->held);

        $old = SourceLedger::empty();
        $old->record('laravel', '13', 'security_until', SourceNames::EOL_LARAVEL, '2028-09-17', '2026-10-10');
        [$result, $policy] = $this->runWith($sources, $old);
        self::assertSame([], $result->held);
        self::assertSame('2028-09-17', $this->field($policy, 'laravel', '13', 'security_until'));
    }

    #[Test]
    public function aLaterActiveDateIsWrittenAsALooseningWarning(): void
    {
        $sources = new Sources(endoflifeLaravel: [$this->release('13', '2027-09-30', '2028-03-17')]);
        [$result, $policy] = $this->runWith($sources);

        self::assertSame('loosen-warning', $result->changes[0]->kind);
        self::assertSame('2027-09-30', $this->field($policy, 'laravel', '13', 'active_until'));
    }

    #[Test]
    public function anEarlierDateTightensAndIsWritten(): void
    {
        $sources = new Sources(phpStates: $this->states('2028-06-30'));
        [$result, $policy] = $this->runWith($sources);

        self::assertSame('tighten', $result->changes[0]->kind);
        self::assertSame('2028-06-30', $this->field($policy, 'php', '8.4', 'security_until'));
    }

    #[Test]
    public function aSourceThatIsDownChangesNothing(): void
    {
        $before = StableJson::encode($this->policy());
        [$result, $policy] = $this->runWith(Sources::empty());

        self::assertFalse($result->anythingToReport());
        self::assertSame($before, StableJson::encode($policy));
    }

    #[Test]
    public function aLineMissingFromAFeedIsKeptAndNotReported(): void
    {
        // The feed knows only 8.4; 8.3 must be left exactly as it was.
        [$result, $policy] = $this->runWith(new Sources(phpStates: $this->states('2028-12-31')));

        self::assertSame([], $result->refused);
        self::assertSame('2027-12-31', $this->field($policy, 'php', '8.3', 'security_until'));
        self::assertSame('8.3.1', $this->field($policy, 'php', '8.3', 'latest_patch'));
    }

    #[Test]
    public function aNewPatchIsNeutralAndStampsTheCheckDate(): void
    {
        [$result, $policy] = $this->runWith(new Sources(phpActive: ['8.3' => '8.3.2']));

        self::assertSame('neutral', $result->changes[0]->kind);
        self::assertSame('8.3.2', $this->field($policy, 'php', '8.3', 'latest_patch'));
        self::assertSame(self::TODAY, $this->field($policy, 'php', '8.3', 'latest_patch_checked'));
    }

    #[Test]
    public function mysqlAddsLtsLinesOnlyAndSkipsInnovationReleases(): void
    {
        $sources = new Sources(endoflifeMysql: [
            $this->release('26.10', null, null, false, false),
            $this->release('10.7', null, null, false, true),
            $this->release('9.7', '2031-04-30', '2034-04-30', false, true),
        ]);
        [$result, $policy] = $this->runWith($sources);

        self::assertNull($policy->components->mysql->versions->{'26.10'} ?? null);
        self::assertTrue($this->field($policy, 'mysql', '10.7', 'current'));
        self::assertCount(1, $result->changes);
    }

    #[Test]
    public function nodeAddsEvenMajorsOnlyAndSkipsOddOnes(): void
    {
        $sources = new Sources(nodeSchedule: [
            '27' => ['maintenance' => '2028-10-01', 'end' => '2030-04-30'],
            '28' => ['maintenance' => '2028-10-20', 'end' => '2031-04-30'],
        ]);
        [$result, $policy] = $this->runWith($sources);

        self::assertNull($policy->components->node->versions->{'27'} ?? null);
        self::assertSame('2031-04-30', $this->field($policy, 'node', '28', 'security_until'));
        self::assertSame('neutral', $result->changes[0]->kind);
    }

    #[Test]
    public function aNewInertiaMajorIsAddedAsCurrent(): void
    {
        [$result, $policy] = $this->runWith(new Sources(npmInertia: ['latest' => '3.9.1']));

        self::assertTrue($this->field($policy, 'inertia', '3', 'current'));
        self::assertTrue($this->field($policy, 'inertia', '2', 'current'));
        self::assertSame('neutral', $result->changes[0]->kind);

        [$again] = $this->runWith(new Sources(npmInertia: ['latest' => '2.9.9']));
        self::assertSame([], $again->changes);
    }

    #[Test]
    public function aComposerMinimumOnAnEolLineIsRefusedWithNoWrite(): void
    {
        $before = StableJson::encode($this->policy());
        [$result, $policy] = $this->runWith(new Sources(
            endoflifeComposer: [$this->release('2.8', null, '2025-11-13', true)],
        ));

        self::assertCount(1, $result->refused);
        self::assertSame('composer', $result->refused[0]->component);
        self::assertSame([], $result->changes);
        self::assertSame($before, StableJson::encode($policy));
    }

    #[Test]
    public function aVueCurrentLineGainsItsFirstEndDateAsATightening(): void
    {
        $sources = new Sources(endoflifeVue: [$this->release('3.5', '2026-09-03', '2027-09-03', true)]);
        [$result, $policy] = $this->runWith($sources);

        self::assertSame('tighten', $result->changes[0]->kind);
        self::assertSame('2027-09-03', $this->field($policy, 'vue', '3', 'security_until'));
        self::assertNull($this->field($policy, 'vue', '3', 'current'));
    }

    #[Test]
    public function theReportNamesEveryFieldTheRobotNeeds(): void
    {
        $sources = new Sources(phpStates: $this->states('2029-12-31'), endoflifePhp: [$this->release('8.4', '2026-12-31', '2028-12-31')]);
        [$result] = $this->runWith($sources);

        $report = json_decode(StableJson::encode($result->reportObject(self::TODAY, false, ['x: down'])), true);
        self::assertIsArray($report);
        foreach (['changes', 'held', 'refused', 'disagreements', 'source_errors'] as $key) {
            self::assertArrayHasKey($key, $report);
        }
        self::assertSame(
            ['component', 'line', 'field', 'old', 'new', 'class', 'sources', 'reason'],
            array_keys($report['held'][0]),
        );
        self::assertCount(1, $report['disagreements']);
    }
}
