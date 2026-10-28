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

final class PolicyStateTest extends TestCase
{
    /**
     * PHP 8.2: active until 2024-12-31, security until 2026-12-31, then
     * eol_grace_days (90) of grace. One Last Breath is 2026-12-31, the last
     * day before PHP 8.2's EOL.
     *
     * @return array<string, array{0: string, 1: State}>
     */
    public static function php82BoundaryProvider(): array
    {
        return [
            'last active day' => ['2024-12-31', State::Active],
            'first security day' => ['2025-01-01', State::Security],
            'last security day' => ['2026-12-31', State::Security],
            'first eol day' => ['2027-01-01', State::Eol],
            'last grace day' => ['2027-03-31', State::Eol],
            'day after grace' => ['2027-04-01', State::EolGraceExpired],
        ];
    }

    #[Test]
    #[DataProvider('php82BoundaryProvider')]
    public function stateOfPhp82AtTheBoundaries(string $date, State $expected): void
    {
        $policy = Policy::bundled();
        self::assertSame($expected, $policy->stateOf('php', '8.2', new DateTimeImmutable($date)));
    }

    #[Test]
    public function oldestSupportedPhpOn20261027(): void
    {
        $policy = Policy::bundled();
        self::assertSame('8.2', $policy->oldestSupported('php', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function oldestSupportedPhpOn20270101(): void
    {
        $policy = Policy::bundled();
        self::assertSame('8.3', $policy->oldestSupported('php', new DateTimeImmutable('2027-01-01')));
    }

    #[Test]
    public function oldestSupportedIntegerVersionKeysOn20261027(): void
    {
        $policy = Policy::bundled();
        self::assertSame('12', $policy->oldestSupported('laravel', new DateTimeImmutable('2026-10-27')));
        self::assertSame('22', $policy->oldestSupported('node', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function unknownComponentThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::bundled()->stateOf('cobol', '8.2', new DateTimeImmutable('2026-10-27'));
    }

    #[Test]
    public function unknownVersionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::bundled()->stateOf('php', '7.4', new DateTimeImmutable('2026-10-27'));
    }

    #[Test]
    public function versionWithoutSecurityUntilIsNamedAndRefused(): void
    {
        $policy = Policy::fromFile(__DIR__ . '/fixtures/no-security-until.json');
        try {
            $policy->stateOf('php', '9.9', new DateTimeImmutable('2026-10-27'));
            self::fail('Expected an exception for a version without security_until.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('9.9', $e->getMessage());
            self::assertStringContainsString('php', $e->getMessage());
        }
    }

    #[Test]
    public function rulesAreExposed(): void
    {
        $rules = Policy::bundled()->rules();
        self::assertSame('high', $rules['audit_severity_threshold']);
        self::assertSame(['dev-*', '*'], $rules['banned_constraints']);
        self::assertSame(90, $rules['eol_grace_days']);
    }

    #[Test]
    public function toolMinimumComposerFromBundledPolicy(): void
    {
        self::assertSame('2.8.0', Policy::bundled()->toolMinimum('composer'));
    }

    #[Test]
    public function toolMinimumUnlistedToolIsNull(): void
    {
        self::assertNull(Policy::bundled()->toolMinimum('npm'));
    }

    // --- current lines and versions ahead of the policy ---

    #[Test]
    public function currentLineIsActiveOnEveryDate(): void
    {
        $policy = Policy::bundled();
        self::assertSame(State::Active, $policy->stateOf('vue', '3', new DateTimeImmutable('2026-10-27')));
        self::assertSame(State::Active, $policy->stateOf('vue', '3', new DateTimeImmutable('2035-01-01')));
    }

    #[Test]
    public function currentLineHasNoDates(): void
    {
        $policy = Policy::bundled();
        self::assertNull($policy->activeUntil('vue', '3'));
        self::assertNull($policy->securityUntil('vue', '3'));
        self::assertNull($policy->daysToEol('vue', '3', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function currentLineIsSupported(): void
    {
        $policy = Policy::bundled();
        self::assertSame(['3'], $policy->supported('vue', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function vuePatchResolvesToTheCurrentLine(): void
    {
        $policy = Policy::bundled();
        self::assertSame('3', $policy->resolveVersion('vue', '3.5.22'));
        self::assertSame(State::Active, $policy->stateOf('vue', '3.5.22', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function inertiaTwoIsActive(): void
    {
        $policy = Policy::bundled();
        self::assertSame(State::Active, $policy->stateOf('inertia', '2', new DateTimeImmutable('2026-10-27')));
        self::assertSame(State::Active, $policy->stateOf('inertia', '2.0.0', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function versionAheadOfPolicyIsActive(): void
    {
        $policy = Policy::bundled();
        self::assertTrue($policy->isAheadOfPolicy('node', '28'));
        self::assertSame(State::Active, $policy->stateOf('node', '28', new DateTimeImmutable('2026-10-27')));
    }

    #[Test]
    public function isAheadOfPolicyIsFalseForListedAndGapVersions(): void
    {
        $policy = Policy::bundled();
        self::assertFalse($policy->isAheadOfPolicy('php', '8.3'));
        self::assertFalse($policy->isAheadOfPolicy('php', '8.3.12'));
        self::assertFalse($policy->isAheadOfPolicy('php', '8.0'));
        self::assertFalse($policy->isAheadOfPolicy('php', '7.4'));
        self::assertTrue($policy->isAheadOfPolicy('php', '9.0'));
    }

    #[Test]
    public function gapVersionBetweenLinesThrows(): void
    {
        $policy = Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'php' => [
                    'versions' => [
                        '8.1' => ['security_until' => '2025-12-31'],
                        '8.9' => ['security_until' => '2030-12-31'],
                    ],
                ],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]);
        $this->expectException(InvalidArgumentException::class);
        $policy->stateOf('php', '8.5', new DateTimeImmutable('2026-10-27'));
    }

    #[Test]
    public function isAheadOfPolicyThrowsForAnUnknownComponent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Policy::bundled()->isAheadOfPolicy('cobol', '8.2');
    }

    #[Test]
    public function unlistedVersionOfAComponentWithNoLinesIsActive(): void
    {
        $policy = Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'inertia' => ['versions' => []],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]);
        self::assertTrue($policy->isAheadOfPolicy('inertia', '2.1'));
        self::assertSame(State::Active, $policy->stateOf('inertia', '2.1', new DateTimeImmutable('2026-10-27')));
    }
}
