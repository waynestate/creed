<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

/**
 * The override cap (rules.override_max_days): how many days past today an
 * override may run before PROCTOR fails it and refuses to apply it. The
 * bundled policy records it, the reader must answer it, and a policy shaped
 * before the rule existed still reads, keeping the stack's default of 90.
 * CREED allows no permanent override, so anything but a positive integer is
 * refused.
 */
final class PolicyOverrideMaxDaysTest extends TestCase
{
    #[Test]
    public function bundledPolicyAnswersNinetyDays(): void
    {
        self::assertSame(90, Policy::bundled()->overrideMaxDays());
    }

    /**
     * A policy shaped before override_max_days existed, built in memory:
     * every known rule still answers, and the new accessor falls back to
     * the stack's default.
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
    public function aPolicyWithoutTheKeyAnswersNinetyDays(): void
    {
        self::assertSame(90, $this->olderShapedPolicy()->overrideMaxDays());
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

    #[Test]
    public function aRecordedValueIsReturnedAsGiven(): void
    {
        self::assertSame(30, self::policyWithRules(['override_max_days' => 30])->overrideMaxDays());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'string' => ['90'],
            'float' => [1.5],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function anInvalidValueThrowsNamingTheKey(mixed $value): void
    {
        $policy = self::policyWithRules(['override_max_days' => $value]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('override_max_days');
        $policy->overrideMaxDays();
    }
}
