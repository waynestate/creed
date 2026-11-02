<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;
use Waynestate\Creed\State;

/**
 * The older-reader contract (practice 10): an older reader must still read a
 * newer policy file.
 *
 * fixtures/policy-reader-v1/policy-v1.json is a frozen snapshot of a small
 * policy shaped as the 0.1.x reader expects (the reader at 0.1.4 is this
 * reader). policy-future.json is the same file plus what a newer CREED may
 * add: a new top-level key (edition), new per-version keys (images filled,
 * stage_dates, notes), a new component (vue) and a new rules key
 * (abandoned_packages). schema_version stays 1 in both files.
 *
 * Change the fixtures only with a schema_version bump (MAINTAINING.md). The
 * old frontman does not refuse the new setlist.
 */
final class OlderReaderContractTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/policy-reader-v1';

    private static function v1(): Policy
    {
        return Policy::fromFile(self::FIXTURES . '/policy-v1.json');
    }

    private static function future(): Policy
    {
        return Policy::fromFile(self::FIXTURES . '/policy-future.json');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function knownLines(): array
    {
        return [
            'php 8.4' => ['php', '8.4'],
            'php 8.5' => ['php', '8.5'],
            'laravel 13' => ['laravel', '13'],
            'node 24' => ['node', '24'],
        ];
    }

    #[Test]
    #[DataProvider('knownLines')]
    public function everyKnownLineStillResolvesWithTheSameDates(string $component, string $version): void
    {
        self::assertSame(
            self::v1()->line($component, $version),
            self::future()->line($component, $version),
            "The future file must not change line {$component} {$version}.",
        );
    }

    #[Test]
    public function thePlainLineKeepsItsDates(): void
    {
        // Pins the fixture itself, so a silent edit to the dates fails here.
        self::assertSame([
            'active_until' => '2026-12-31',
            'security_until' => '2028-12-31',
            'latest_patch' => '8.4.27',
            'latest_patch_checked' => '2026-10-28',
            'current' => null,
        ], self::v1()->line('php', '8.4'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: State}>
     */
    public static function stateOfDates(): array
    {
        return [
            'php 8.4 active before active_until' => ['php', '8.4', '2026-11-02', State::Active],
            'php 8.4 active on active_until' => ['php', '8.4', '2026-12-31', State::Active],
            'php 8.4 security after active_until' => ['php', '8.4', '2027-01-01', State::Security],
            'php 8.4 security on security_until' => ['php', '8.4', '2028-12-31', State::Security],
            'php 8.4 eol the day after security_until' => ['php', '8.4', '2029-01-01', State::Eol],
            'php 8.4 eol on the last grace day' => ['php', '8.4', '2029-03-31', State::Eol],
            'php 8.4 grace expired after the window' => ['php', '8.4', '2029-04-01', State::EolGraceExpired],
            'php 8.5 active' => ['php', '8.5', '2027-06-01', State::Active],
            'php 8.5 security' => ['php', '8.5', '2028-06-01', State::Security],
            'php 8.5 eol inside the grace window' => ['php', '8.5', '2030-01-01', State::Eol],
            'php 8.5 grace expired' => ['php', '8.5', '2030-04-01', State::EolGraceExpired],
            'laravel 13 active on active_until' => ['laravel', '13', '2027-09-30', State::Active],
            'laravel 13 security on security_until' => ['laravel', '13', '2028-03-17', State::Security],
            'laravel 13 eol on the last grace day' => ['laravel', '13', '2028-06-15', State::Eol],
            'laravel 13 grace expired' => ['laravel', '13', '2028-06-16', State::EolGraceExpired],
            'node 24 active on active_until' => ['node', '24', '2026-10-20', State::Active],
            'node 24 security after active_until' => ['node', '24', '2026-10-21', State::Security],
            'node 24 eol on the last grace day' => ['node', '24', '2028-07-29', State::Eol],
            'node 24 grace expired' => ['node', '24', '2028-07-30', State::EolGraceExpired],
        ];
    }

    #[Test]
    #[DataProvider('stateOfDates')]
    public function stateOfAnswersMatchThePlainFile(string $component, string $version, string $date, State $expected): void
    {
        $on = new DateTimeImmutable($date);
        self::assertSame($expected, self::v1()->stateOf($component, $version, $on));
        self::assertSame(
            self::v1()->stateOf($component, $version, $on),
            self::future()->stateOf($component, $version, $on),
            "stateOf for {$component} {$version} on {$date} must not change.",
        );
    }

    #[Test]
    public function theRestOfTheReaderApiAnswersTheSame(): void
    {
        $on = new DateTimeImmutable('2027-06-01');
        $late = new DateTimeImmutable('2028-06-16');
        self::assertSame(self::v1()->supported('php', $on), self::future()->supported('php', $on));
        self::assertSame(['8.4', '8.5'], self::future()->supported('php', $on));
        self::assertSame(self::v1()->oldestSupported('php', $on), self::future()->oldestSupported('php', $on));
        self::assertSame(self::v1()->daysToEol('php', '8.4', $on), self::future()->daysToEol('php', '8.4', $on));
        self::assertSame(self::v1()->latestPatch('php', '8.5'), self::future()->latestPatch('php', '8.5'));
        self::assertSame(self::v1()->toolMinimum('composer'), self::future()->toolMinimum('composer'));
        self::assertSame(self::v1()->isPastGrace('laravel', '13', $late), self::future()->isPastGrace('laravel', '13', $late));
        self::assertTrue(self::future()->isPastGrace('laravel', '13', $late));
        // The known rules keep their values next to the new one.
        self::assertSame(self::v1()->rules()['eol_grace_days'], self::future()->rules()['eol_grace_days']);
        self::assertSame(self::v1()->rules()['banned_constraints'], self::future()->rules()['banned_constraints']);
        self::assertSame(self::v1()->rules()['audit_severity_threshold'], self::future()->rules()['audit_severity_threshold']);
    }

    #[Test]
    public function unknownKeysAreIgnored(): void
    {
        $future = self::future();
        // line() reports exactly the five known fields; images, stage_dates
        // and notes never reach it.
        self::assertSame([
            'active_until' => '2026-12-31',
            'security_until' => '2028-12-31',
            'latest_patch' => '8.4.27',
            'latest_patch_checked' => '2026-10-28',
            'current' => null,
        ], $future->line('php', '8.4'));
        // The new component is readable where the file lists it...
        self::assertTrue($future->hasComponent('vue'));
        self::assertFalse(self::v1()->hasComponent('vue'));
        self::assertSame(State::Active, $future->stateOf('vue', '3', new DateTimeImmutable('2027-01-01')));
        // ...and refused by name where the file does not list it.
        $this->expectException(InvalidArgumentException::class);
        self::v1()->stateOf('vue', '3', new DateTimeImmutable('2027-01-01'));
    }

    #[Test]
    public function aHigherSchemaVersionIsAcceptedAsToday(): void
    {
        // Today's rule: the reader requires schema_version to be present and
        // never reads its value, so a file that bumps it loads and answers
        // exactly as the same data with schema_version 1 does.
        $raw = (string) file_get_contents(self::FIXTURES . '/policy-future.json');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::fail('policy-future.json must decode to an object.');
        }
        /** @var array<string, mixed> $data */
        $data['schema_version'] = 2;
        $policy = Policy::fromArray($data);

        $on = new DateTimeImmutable('2027-06-01');
        self::assertSame(self::future()->stateOf('php', '8.4', $on), $policy->stateOf('php', '8.4', $on));
        self::assertSame(self::future()->line('php', '8.4'), $policy->line('php', '8.4'));
        self::assertSame(self::future()->supported('php', $on), $policy->supported('php', $on));
        self::assertSame(self::future()->toolMinimum('composer'), $policy->toolMinimum('composer'));
    }
}
