<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;
use Waynestate\Creed\State;

/**
 * Pins the exact date on which a version changes state.
 *
 * The policy is inline, not the real policy.json: one version line with
 * active_until 2025-06-30, security_until 2026-06-30 and eol_grace_days 90,
 * so the last grace day is 2026-09-28. "Today" is always the date under
 * test, passed the way Policy already accepts it, never the real clock.
 */
final class PolicyDateBoundaryTest extends TestCase
{
    private ?string $policyFile = null;

    protected function tearDown(): void
    {
        if ($this->policyFile !== null) {
            @unlink($this->policyFile);
            $this->policyFile = null;
        }
    }

    /**
     * The inline policy, loaded the only way the reader accepts a policy
     * that is not the bundled one: as a file.
     */
    private function inlinePolicy(): Policy
    {
        $path = tempnam(sys_get_temp_dir(), 'creed-boundary-');
        if ($path === false) {
            self::fail('Could not create a temporary policy file.');
        }
        $json = json_encode([
            'schema_version' => 1,
            'components' => [
                'php' => [
                    'versions' => [
                        '8.9' => [
                            'active_until' => '2025-06-30',
                            'security_until' => '2026-06-30',
                        ],
                    ],
                ],
            ],
            'rules' => [
                'audit_severity_threshold' => 'high',
                'banned_constraints' => ['dev-*', '*'],
                'eol_grace_days' => 90,
            ],
        ], JSON_PRETTY_PRINT);
        if ($json === false) {
            self::fail('Could not encode the inline policy.');
        }
        file_put_contents($path, $json);
        $this->policyFile = $path;
        return Policy::fromFile($path);
    }

    /**
     * The boundaries of the inline line, named by their date.
     *
     * @return array<string, array{0: string, 1: State}>
     */
    public static function dateBoundaryProvider(): array
    {
        return [
            '2025-06-29' => ['2025-06-29', State::Active], // day before the active end
            '2025-06-30' => ['2025-06-30', State::Active], // the active end day
            '2025-07-01' => ['2025-07-01', State::Security], // the day after the active end
            '2026-06-29' => ['2026-06-29', State::Security], // day before the security end
            '2026-06-30' => ['2026-06-30', State::Security], // the security end day
            '2026-07-01' => ['2026-07-01', State::Eol], // the day after the security end
            '2026-09-28' => ['2026-09-28', State::Eol], // the last grace day
            '2026-09-29' => ['2026-09-29', State::EolGraceExpired], // the day after the grace window
        ];
    }

    #[Test]
    #[DataProvider('dateBoundaryProvider')]
    public function stateOfAtTheDateBoundaries(string $date, State $expected): void
    {
        $policy = $this->inlinePolicy();
        self::assertSame($expected, $policy->stateOf('php', '8.9', new DateTimeImmutable($date)));
    }
}
