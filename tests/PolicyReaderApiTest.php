<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;
use Waynestate\Creed\State;

/**
 * The reader API the four consumers (PROCTOR, BOOSTER, ROLLCALL, ADVISOR)
 * call. Each member gets its own test, including the null cases, because the
 * reader does not guess.
 */
final class PolicyReaderApiTest extends TestCase
{
    /**
     * A small policy built in memory, so the tests do not depend on the
     * bundled file and can cover the null cases directly.
     */
    private function testPolicy(): Policy
    {
        return Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'php' => [
                    'versions' => [
                        '8.1' => ['security_until' => '2025-12-31'],
                        '8.2' => [
                            'active_until' => '2024-12-31',
                            'security_until' => '2026-12-31',
                            'latest_patch' => '8.2.35',
                            'latest_patch_checked' => '2026-10-28',
                        ],
                        '8.3' => [
                            'active_until' => '2025-12-31',
                            'security_until' => '2027-12-31',
                            'latest_patch' => '8.3.36',
                            'latest_patch_checked' => '2026-10-28',
                        ],
                    ],
                ],
                'laravel' => [
                    'versions' => [
                        '10' => ['active_until' => '2024-08-06', 'security_until' => '2025-02-04'],
                        '13' => ['active_until' => '2027-09-17', 'security_until' => '2028-03-17'],
                    ],
                ],
                'vue' => [
                    'versions' => [
                        '3' => ['current' => true],
                    ],
                ],
                'empty' => ['versions' => []],
            ],
            'tools' => [
                'composer' => ['minimum' => '2.8.0'],
                'node' => ['minimum' => '22.0.0'],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]);
    }

    // --- State::isSupported ---

    #[Test]
    public function isSupportedIsTrueForActiveAndSecurity(): void
    {
        self::assertTrue(State::Active->isSupported());
        self::assertTrue(State::Security->isSupported());
    }

    #[Test]
    public function isSupportedIsFalseForTheEolStates(): void
    {
        self::assertFalse(State::Eol->isSupported());
        self::assertFalse(State::EolGraceExpired->isSupported());
    }

    // --- fromArray and origin ---

    #[Test]
    public function fromArrayBuildsAReadablePolicy(): void
    {
        $policy = $this->testPolicy();
        self::assertSame(
            State::Security,
            $policy->stateOf('php', '8.2', new DateTimeImmutable('2026-10-27')),
        );
    }

    #[Test]
    public function fromArrayWithoutSchemaVersionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::fromArray(['components' => []]);
    }

    #[Test]
    public function originReportsHowThePolicyWasLoaded(): void
    {
        self::assertSame('bundled', Policy::bundled()->origin());
        self::assertSame('array', $this->testPolicy()->origin());
        $path = __DIR__ . '/fixtures/good.json';
        self::assertSame($path, Policy::fromFile($path)->origin());
    }

    // --- tryStateOf ---

    #[Test]
    public function tryStateOfReturnsTheStateForAKnownVersion(): void
    {
        $policy = $this->testPolicy();
        self::assertSame(
            State::Security,
            $policy->tryStateOf('php', '8.2', new DateTimeImmutable('2026-10-27')),
        );
    }

    #[Test]
    public function tryStateOfIsNullForAnUnknownComponent(): void
    {
        $policy = $this->testPolicy();
        self::assertNull($policy->tryStateOf('cobol', '8.2', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function tryStateOfIsNullForAnUnknownVersion(): void
    {
        $policy = $this->testPolicy();
        self::assertNull($policy->tryStateOf('php', '7.4', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function tryStateOfIsNullForANullVersion(): void
    {
        $policy = $this->testPolicy();
        self::assertNull($policy->tryStateOf('php', null, new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function tryStateOfIsNullWhenSecurityUntilIsMissing(): void
    {
        $policy = Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'php' => ['versions' => ['9.9' => ['active_until' => '2026-01-01']]],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]);
        self::assertNull($policy->tryStateOf('php', '9.9', new DateTimeImmutable('2026-10-27')));
    }

    // --- hasComponent, components, tools ---

    #[Test]
    public function hasComponentReportsPresence(): void
    {
        $policy = $this->testPolicy();
        self::assertTrue($policy->hasComponent('php'));
        self::assertFalse($policy->hasComponent('cobol'));
    }

    #[Test]
    public function componentsListsEveryComponentInFileOrder(): void
    {
        self::assertSame(['php', 'laravel', 'vue', 'empty'], $this->testPolicy()->components());
    }

    #[Test]
    public function toolsListsEveryToolInFileOrder(): void
    {
        self::assertSame(['composer', 'node'], $this->testPolicy()->tools());
    }

    // --- versions ---

    #[Test]
    public function versionsAreSortedOldestFirst(): void
    {
        self::assertSame(['8.1', '8.2', '8.3'], $this->testPolicy()->versions('php'));
    }

    #[Test]
    public function versionsIsEmptyForAnUnknownComponent(): void
    {
        self::assertSame([], $this->testPolicy()->versions('cobol'));
    }

    #[Test]
    public function versionsIsEmptyForAComponentWithNoVersions(): void
    {
        self::assertSame([], $this->testPolicy()->versions('empty'));
    }

    // --- line ---

    #[Test]
    public function lineReturnsExactlyTheFiveFields(): void
    {
        $line = $this->testPolicy()->line('php', '8.2');
        self::assertSame([
            'active_until' => '2024-12-31',
            'security_until' => '2026-12-31',
            'latest_patch' => '8.2.35',
            'latest_patch_checked' => '2026-10-28',
            'current' => null,
        ], $line);
    }

    #[Test]
    public function lineIsNullForAnUnknownVersion(): void
    {
        self::assertNull($this->testPolicy()->line('php', '7.4'));
    }

    #[Test]
    public function lineIsNullForAnUnknownComponent(): void
    {
        self::assertNull($this->testPolicy()->line('cobol', '8.2'));
    }

    #[Test]
    public function lineLeavesAbsentFieldsNull(): void
    {
        $line = $this->testPolicy()->line('php', '8.1');
        self::assertSame([
            'active_until' => null,
            'security_until' => '2025-12-31',
            'latest_patch' => null,
            'latest_patch_checked' => null,
            'current' => null,
        ], $line);
    }

    #[Test]
    public function lineReportsCurrentLines(): void
    {
        $line = $this->testPolicy()->line('vue', '3');
        self::assertSame([
            'active_until' => null,
            'security_until' => null,
            'latest_patch' => null,
            'latest_patch_checked' => null,
            'current' => true,
        ], $line);
    }

    // --- activeUntil, securityUntil, latestPatchChecked ---

    #[Test]
    public function activeUntilReturnsADate(): void
    {
        $date = $this->testPolicy()->activeUntil('php', '8.2');
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2024-12-31', $date->format('Y-m-d'));
    }

    #[Test]
    public function activeUntilIsNullWhenAbsent(): void
    {
        self::assertNull($this->testPolicy()->activeUntil('php', '8.1'));
    }

    #[Test]
    public function activeUntilIsNullForAnUnknownVersion(): void
    {
        self::assertNull($this->testPolicy()->activeUntil('php', '7.4'));
    }

    #[Test]
    public function securityUntilReturnsADate(): void
    {
        $date = $this->testPolicy()->securityUntil('php', '8.2');
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-12-31', $date->format('Y-m-d'));
    }

    #[Test]
    public function securityUntilIsNullForAnUnknownVersion(): void
    {
        self::assertNull($this->testPolicy()->securityUntil('php', '7.4'));
    }

    #[Test]
    public function latestPatchCheckedReturnsTheDate(): void
    {
        self::assertSame('2026-10-28', $this->testPolicy()->latestPatchChecked('php', '8.2'));
    }

    #[Test]
    public function latestPatchCheckedIsNullWhenAbsent(): void
    {
        self::assertNull($this->testPolicy()->latestPatchChecked('php', '8.1'));
    }

    // --- supported and oldestSupported ---

    #[Test]
    public function supportedListsSupportedVersionsOldestFirst(): void
    {
        // On 2026-10-27: 8.1 is EolGraceExpired, 8.2 is Security, 8.3 is
        // Security.
        $policy = $this->testPolicy();
        self::assertSame(['8.2', '8.3'], $policy->supported('php', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function supportedIsEmptyWhenNothingIsSupported(): void
    {
        // On 2028-06-01 every PHP version is past its grace period.
        $policy = $this->testPolicy();
        self::assertSame([], $policy->supported('php', new DateTimeImmutable('2028-06-01')));
    }

    #[Test]
    public function supportedThrowsForAnUnknownComponent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->testPolicy()->supported('cobol', new DateTimeImmutable('2026-10-27'));
    }

    #[Test]
    public function oldestSupportedIsTheFirstSupportedVersion(): void
    {
        $policy = $this->testPolicy();
        self::assertSame('8.2', $policy->oldestSupported('php', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function oldestSupportedIsNullWhenNothingIsSupported(): void
    {
        $policy = $this->testPolicy();
        self::assertNull($policy->oldestSupported('php', new DateTimeImmutable('2028-06-01')));
    }

    // --- daysToEol and isPastGrace ---

    #[Test]
    public function daysToEolCountsWholeDaysUntilSecurityUntil(): void
    {
        // 8.2 is security until 2026-12-31.
        $policy = $this->testPolicy();
        self::assertSame(1, $policy->daysToEol('php', '8.2', new DateTimeImmutable('2026-12-30')));
        self::assertSame(0, $policy->daysToEol('php', '8.2', new DateTimeImmutable('2026-12-31')));
        self::assertSame(-1, $policy->daysToEol('php', '8.2', new DateTimeImmutable('2027-01-01')));
    }

    #[Test]
    public function daysToEolIsNullForAnUnknownVersion(): void
    {
        $policy = $this->testPolicy();
        self::assertNull($policy->daysToEol('php', '7.4', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function isPastGraceIsTrueOnlyAfterTheGracePeriod(): void
    {
        // 8.1 is security until 2025-12-31, with 90 grace days ending
        // 2026-03-31.
        $policy = $this->testPolicy();
        self::assertFalse($policy->isPastGrace('php', '8.1', new DateTimeImmutable('2026-01-01')));
        self::assertFalse($policy->isPastGrace('php', '8.1', new DateTimeImmutable('2026-03-31')));
        self::assertTrue($policy->isPastGrace('php', '8.1', new DateTimeImmutable('2026-04-01')));
    }

    #[Test]
    public function isPastGraceIsFalseForAnUnknownVersion(): void
    {
        $policy = $this->testPolicy();
        self::assertFalse($policy->isPastGrace('php', '7.4', new DateTimeImmutable('2026-10-27')));
    }

    // --- resolveVersion ---

    #[Test]
    public function resolveVersionMapsAPatchToItsLine(): void
    {
        $policy = Policy::bundled();
        self::assertSame('8.3', $policy->resolveVersion('php', '8.3.12'));
        self::assertSame('13', $policy->resolveVersion('laravel', '13.1'));
    }

    #[Test]
    public function resolveVersionMatchesAnExactLine(): void
    {
        $policy = Policy::bundled();
        self::assertSame('8.3', $policy->resolveVersion('php', '8.3'));
    }

    #[Test]
    public function resolveVersionPicksTheLongestPrefix(): void
    {
        $policy = Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'php' => [
                    'versions' => [
                        '8' => ['security_until' => '2020-01-01'],
                        '8.3' => ['security_until' => '2027-12-31'],
                    ],
                ],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]);
        self::assertSame('8.3', $policy->resolveVersion('php', '8.3.12'));
    }

    #[Test]
    public function resolveVersionIsNullWhenNothingMatches(): void
    {
        $policy = Policy::bundled();
        self::assertNull($policy->resolveVersion('php', '7.4.3'));
        self::assertNull($policy->resolveVersion('cobol', '8.3.12'));
    }
}
