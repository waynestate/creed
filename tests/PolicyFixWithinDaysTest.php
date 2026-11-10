<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

/**
 * The advisory fix window (rules.fix_within_days): how many days an app has
 * to ship a fix once composer audit reports an advisory of a severity. The
 * bundled policy has not chosen a number yet, so the reader answers null
 * until the maintainers do, and it refuses anything that is not a positive
 * integer. Creed Bratton keeps every window open on principle; the policy
 * keeps its numbers written down.
 */
final class PolicyFixWithinDaysTest extends TestCase
{
    #[Test]
    public function theBundledPolicyStatesNoWindowYet(): void
    {
        foreach (Policy::SEVERITIES as $severity) {
            self::assertNull(Policy::bundled()->fixWithinDays($severity), "The bundled policy states a window for {$severity}.");
        }
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
    public function aPolicyWithoutTheBlockAnswersNull(): void
    {
        self::assertNull(self::policyWithRules(['eol_grace_days' => 90])->fixWithinDays('critical'));
    }

    #[Test]
    public function aRecordedValueIsReturnedAsGiven(): void
    {
        $policy = self::policyWithRules(['fix_within_days' => ['critical' => 7, 'high' => 30]]);

        self::assertSame(7, $policy->fixWithinDays('critical'));
        self::assertSame(30, $policy->fixWithinDays('high'));
        self::assertNull($policy->fixWithinDays('medium'));
        self::assertNull($policy->fixWithinDays('low'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownSeverities(): array
    {
        return [
            'moderate' => ['moderate'],
            'uppercase' => ['CRITICAL'],
            'empty' => [''],
            'info' => ['info'],
        ];
    }

    #[Test]
    #[DataProvider('unknownSeverities')]
    public function anUnknownSeverityIsRefused(string $severity): void
    {
        $policy = self::policyWithRules(['fix_within_days' => ['high' => 30]]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown advisory severity');
        $policy->fixWithinDays($severity);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'string' => ['7'],
            'float' => [7.5],
            'null' => [null],
            'true' => [true],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function anInvalidValueIsRefused(mixed $value): void
    {
        $policy = self::policyWithRules(['fix_within_days' => ['high' => $value]]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fix_within_days.high');
        $policy->fixWithinDays('high');
    }

    #[Test]
    public function severitiesAreMostSevereFirst(): void
    {
        self::assertSame(['critical', 'high', 'medium', 'low'], Policy::SEVERITIES);
    }
}
