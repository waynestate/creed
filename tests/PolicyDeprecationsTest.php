<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

/**
 * The deprecations registry (D-174): announcements of removed APIs and
 * the 28-day window a removal must respect. The bundled policy records
 * the lesson that created the rule, the reader must answer it, and a
 * policy shaped before the list existed still reads, empty.
 */
final class PolicyDeprecationsTest extends TestCase
{
    #[Test]
    public function bundledPolicyListsTheWayneCasShim(): void
    {
        $entries = Policy::bundled()->deprecations();
        self::assertCount(1, $entries);
        self::assertSame([
            'package' => 'waynestate/sesame',
            'api' => 'the wayne-cas shim',
            'announced_in' => '0.7.5',
            'announced_on' => '2026-11-14',
            'removal_not_before' => '2026-11-14',
            'replacement' => "SESAME's own sign-in classes (see the SESAME 0.8.0 upgrade notes)",
            'removed_in' => '0.8.0',
            'note' => 'Announced and removed on the same day, which is why CREED has a deprecation window.',
        ], $entries[0]);
    }

    #[Test]
    public function bundledPolicyWindowIsTwentyEightDays(): void
    {
        self::assertSame(28, Policy::bundled()->deprecationWindowDays());
    }

    /**
     * A policy shaped before deprecations existed, built in memory: every
     * known rule still answers, the new reader falls back to the stack's
     * default window, and the list is empty.
     */
    private function olderShapedPolicy(): Policy
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
            'rules' => ['eol_grace_days' => 90],
        ]);
    }

    #[Test]
    public function olderShapedPolicyHasNoDeprecations(): void
    {
        $policy = $this->olderShapedPolicy();
        self::assertSame([], $policy->deprecations());
        self::assertSame(28, $policy->deprecationWindowDays());
    }

    /**
     * @param array<int|string, mixed> $deprecations
     */
    private static function policyWithDeprecations(array $deprecations): Policy
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
            'rules' => ['eol_grace_days' => 90],
            'deprecations' => $deprecations,
        ]);
    }

    /**
     * One announcement of the wayne-cas shim, dated 2026-11-14, so the
     * 28-day window ends on 2026-12-12.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private static function announcement(string $removalNotBefore, array $extra = []): array
    {
        return [
            'package' => 'waynestate/sesame',
            'api' => 'the wayne-cas shim',
            'announced_in' => '0.7.5',
            'announced_on' => '2026-11-14',
            'removal_not_before' => $removalNotBefore,
            'replacement' => "SESAME's own sign-in classes",
        ] + $extra;
    }

    #[Test]
    public function anAnnouncementInsideTheWindowIsRefused(): void
    {
        // Announced 2026-11-14, window 28 days: removal may not come
        // before 2026-12-12, so 2026-12-11 is one day early.
        $policy = self::policyWithDeprecations([self::announcement('2026-12-11')]);
        try {
            $policy->deprecations();
            self::fail('Expected an InvalidArgumentException for a removal inside the window.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('waynestate/sesame', $e->getMessage());
            self::assertStringContainsString('window', $e->getMessage());
        }
    }

    #[Test]
    public function anAnnouncementOnTheLastDayOfTheWindowIsAccepted(): void
    {
        $entry = self::announcement('2026-12-12');
        $policy = self::policyWithDeprecations([$entry]);
        self::assertSame([$entry], $policy->deprecations());
    }

    #[Test]
    public function aRemovedEntryIsHistoryAndSkipsTheWindow(): void
    {
        // Same-day removal, the wayne-cas shim's actual history: the
        // removed_in key makes it history, so no window applies.
        $entry = self::announcement('2026-11-14', ['removed_in' => '0.8.0']);
        $policy = self::policyWithDeprecations([$entry]);
        self::assertSame([$entry], $policy->deprecations());
    }

    #[Test]
    public function aMissingRequiredKeyIsRefused(): void
    {
        $entry = self::announcement('2026-12-12');
        unset($entry['replacement']);
        $policy = self::policyWithDeprecations([$entry]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('replacement');
        $policy->deprecations();
    }

    #[Test]
    public function aDateThatIsNotARealDateIsRefused(): void
    {
        // February 2026 has 28 days; 2026-02-30 is not a calendar date.
        $policy = self::policyWithDeprecations([self::announcement('2026-02-30')]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('real calendar date');
        $policy->deprecations();
    }
}
