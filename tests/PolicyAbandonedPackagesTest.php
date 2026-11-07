<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

/**
 * The abandoned-package rule (rules.abandoned_packages): how composer audit
 * treats a package that is abandoned, with Composer's own three answers.
 * The bundled policy records it, the reader must answer it, and a policy
 * shaped before the rule existed still reads, defaulting to Composer's own
 * default of fail.
 */
final class PolicyAbandonedPackagesTest extends TestCase
{
    #[Test]
    public function bundledPolicyFailsOnAnAbandonedPackage(): void
    {
        self::assertSame('fail', Policy::bundled()->abandonedPackages());
    }

    /**
     * A policy shaped before abandoned_packages existed, built in memory:
     * every known rule still answers, and the new accessor falls back to
     * Composer's own default.
     */
    private function olderShapedPolicy(): Policy
    {
        return Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'laravel' => [
                    'versions' => [
                        '10' => ['active_until' => '2024-08-06', 'security_until' => '2025-02-04'],
                        '13' => ['active_until' => '2027-09-30', 'security_until' => '2028-03-17'],
                    ],
                ],
            ],
            'rules' => ['eol_grace_days' => 90],
        ]);
    }

    #[Test]
    public function aPolicyWithoutTheKeyAnswersFail(): void
    {
        self::assertSame('fail', $this->olderShapedPolicy()->abandonedPackages());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function recordedValues(): array
    {
        return [
            'report' => ['report'],
            'ignore' => ['ignore'],
        ];
    }

    /**
     * @param array<int|string, mixed> $rules
     */
    private static function policyWithRules(array $rules): Policy
    {
        return Policy::fromArray([
            'schema_version' => 1,
            'components' => [
                'laravel' => [
                    'versions' => [
                        '13' => ['active_until' => '2027-09-30', 'security_until' => '2028-03-17'],
                    ],
                ],
            ],
            'rules' => $rules,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rulesWithAbandoned(mixed $abandoned): array
    {
        return [
            'audit_severity_threshold' => 'high',
            'banned_constraints' => ['dev-*', '*'],
            'eol_grace_days' => 90,
            'abandoned_packages' => $abandoned,
        ];
    }

    #[Test]
    #[DataProvider('recordedValues')]
    public function aRecordedValueIsReturnedAsGiven(string $value): void
    {
        self::assertSame($value, self::policyWithRules(self::rulesWithAbandoned($value))->abandonedPackages());
    }

    #[Test]
    public function anUnknownValueThrowsNamingTheValue(): void
    {
        $policy = self::policyWithRules(self::rulesWithAbandoned('warn'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('warn');
        $policy->abandonedPackages();
    }
}
