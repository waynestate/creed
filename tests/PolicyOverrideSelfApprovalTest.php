<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

/**
 * The self-approval rule (rules.override_self_approval): what PROCTOR's
 * override-approver check does when an override's approver also wrote it,
 * or when it cannot tell. The bundled policy records it, the reader must
 * answer it, and a policy shaped before the rule existed still reads,
 * answering warn. Nobody signs their own note from home, but the first
 * offence only gets a warning.
 */
final class PolicyOverrideSelfApprovalTest extends TestCase
{
    #[Test]
    public function bundledPolicyAnswersWarn(): void
    {
        self::assertSame('warn', Policy::bundled()->overrideSelfApproval());
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
    public function aPolicyWithoutTheKeyAnswersWarn(): void
    {
        self::assertSame('warn', self::policyWithRules(['eol_grace_days' => 90])->overrideSelfApproval());
    }

    #[Test]
    public function failIsReturnedAsGiven(): void
    {
        self::assertSame('fail', self::policyWithRules(['override_self_approval' => 'fail'])->overrideSelfApproval());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'a gate stage' => ['block'],
            'upper case' => ['WARN'],
            'boolean' => [true],
            'integer' => [1],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function anInvalidValueThrowsNamingTheKey(mixed $value): void
    {
        $policy = self::policyWithRules(['override_self_approval' => $value]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('override_self_approval');
        $policy->overrideSelfApproval();
    }
}
