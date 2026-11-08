<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;

/**
 * The deploy-gate stages (gate.default_stage, gate.stages): how a PROCTOR
 * check's rule moves through report, warn and block over time (ADR-0006
 * item 4). The entry with the latest from on or before the run date wins;
 * a check with no entry uses default_stage; a policy shaped before the
 * gate existed keeps today's gate, block. A stage that is not one of the
 * three, or a from that is not YYYY-MM-DD, is refused.
 */
final class PolicyGateStageTest extends TestCase
{
    #[Test]
    public function bundledPolicyBlocksPhpFloor(): void
    {
        self::assertSame('block', Policy::bundled()->gateStage('php-floor', new DateTimeImmutable('2026-11-08')));
    }

    /**
     * A policy shaped before the gate block existed, built in memory: the
     * accessor falls back to today's gate, block.
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
    public function aPolicyWithoutTheGateKeyBlocks(): void
    {
        self::assertSame('block', $this->olderShapedPolicy()->gateStage('php-floor', new DateTimeImmutable('2026-11-08')));
    }

    /**
     * @param array<string, mixed> $gate
     */
    private static function policyWithGate(array $gate): Policy
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
            'gate' => $gate,
        ]);
    }

    #[Test]
    public function aDefaultStageAnswersWhenNoEntryApplies(): void
    {
        $policy = self::policyWithGate(['default_stage' => 'report']);
        self::assertSame('report', $policy->gateStage('php-floor', new DateTimeImmutable('2026-11-08')));
    }

    #[Test]
    public function theLatestFromOnOrBeforeTheRunDateWins(): void
    {
        $entries = [
            ['stage' => 'block', 'from' => '2027-06-01'],
            ['stage' => 'report', 'from' => '2026-01-01'],
            ['stage' => 'warn', 'from' => '2027-01-01'],
        ];
        // The list order must not matter, so both orders answer alike.
        foreach ([$entries, array_reverse($entries)] as $order) {
            $policy = self::policyWithGate([
                'default_stage' => 'block',
                'stages' => ['php-floor' => $order],
            ]);
            self::assertSame('report', $policy->gateStage('php-floor', new DateTimeImmutable('2026-12-31')));
            self::assertSame('warn', $policy->gateStage('php-floor', new DateTimeImmutable('2027-01-01')));
            self::assertSame('warn', $policy->gateStage('php-floor', new DateTimeImmutable('2027-05-31')));
            self::assertSame('block', $policy->gateStage('php-floor', new DateTimeImmutable('2027-06-01')));
        }
    }

    #[Test]
    public function aCheckWithNoEntryGetsTheDefault(): void
    {
        $policy = self::policyWithGate([
            'default_stage' => 'report',
            'stages' => [
                'php-floor' => [
                    ['stage' => 'block', 'from' => '2027-06-01'],
                ],
            ],
        ]);
        self::assertSame('report', $policy->gateStage('mysql-eol', new DateTimeImmutable('2027-06-01')));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedGates(): array
    {
        return [
            'default stage not a stage' => [['default_stage' => 'stop']],
            'entry stage not a stage' => [[
                'stages' => ['php-floor' => [['stage' => 'fail', 'from' => '2027-01-01']]],
            ]],
            'entry from not a date' => [[
                'stages' => ['php-floor' => [['stage' => 'warn', 'from' => '2027-1-1']]],
            ]],
            'entry missing from' => [[
                'stages' => ['php-floor' => [['stage' => 'warn']]],
            ]],
            'stages not a list' => [[
                'stages' => ['php-floor' => 'block'],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $gate
     */
    #[Test]
    #[DataProvider('malformedGates')]
    public function aMalformedGateThrowsNamingTheBlock(array $gate): void
    {
        $policy = self::policyWithGate($gate);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gate');
        $policy->gateStage('php-floor', new DateTimeImmutable('2026-11-08'));
    }
}
