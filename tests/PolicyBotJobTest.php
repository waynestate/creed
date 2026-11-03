<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps .gitlab-ci.yml honest about the policy robot: the policy-bot job
 * exists and runs on its dedicated schedule and by hand, the check-only
 * refresh-patches job skips that schedule, and CREED_BOT_TOKEN never leaks
 * into a variables entry or a printed line. Text assertions only; the CI
 * file is not parsed with a YAML library.
 */
final class PolicyBotJobTest extends TestCase
{
    private const CI_FILE = __DIR__ . '/../.gitlab-ci.yml';

    #[Test]
    public function thePolicyBotJobExists(): void
    {
        self::assertMatchesRegularExpression('/^policy-bot:\s*$/m', self::ciFile());
    }

    #[Test]
    public function thePolicyBotJobRunsOnItsScheduleAndByHand(): void
    {
        $body = self::jobBody('policy-bot');
        self::assertStringContainsString('schedule', $body);
        self::assertStringContainsString('CREED_JOB', $body);
        self::assertStringContainsString('policy-bot', $body);
        self::assertStringContainsString('web', $body);
    }

    #[Test]
    public function theMissingTokenSkipMessageIsExact(): void
    {
        self::assertStringContainsString(
            'CREED_BOT_TOKEN not set; skipping the policy robot',
            self::ciFile(),
        );
    }

    #[Test]
    public function theRefreshPatchesJobSkipsTheRobotSchedule(): void
    {
        self::assertStringContainsString('$CREED_JOB != "policy-bot"', self::jobBody('refresh-patches'));
    }

    #[Test]
    public function theTokenIsNotAVariablesEntry(): void
    {
        foreach (self::lines() as $line) {
            self::assertDoesNotMatchRegularExpression('/^\s*CREED_BOT_TOKEN\s*:/', $line);
        }
    }

    #[Test]
    public function noVariablePassesItselfThrough(): void
    {
        foreach (self::lines() as $line) {
            self::assertDoesNotMatchRegularExpression('/^\s+([A-Z_]+):\s*\$\1\s*$/', $line);
        }
    }

    #[Test]
    public function theTokenNeverRidesAPrintingLine(): void
    {
        foreach (self::lines() as $line) {
            if (!str_contains($line, 'CREED_BOT_TOKEN')) {
                continue;
            }
            if (preg_match('/\b(echo|printf|printenv|env)\b|set -x/', $line) !== 1) {
                continue;
            }
            // Only the -z test may name the token on such a line.
            self::assertStringContainsString('-z', $line, "CREED_BOT_TOKEN on a printing line: {$line}");
        }
    }

    #[Test]
    public function thePolicyBotJobRunsTheRobot(): void
    {
        self::assertStringContainsString('php bin/policy-bot.php run', self::jobBody('policy-bot'));
    }

    /**
     * The CI file as text.
     */
    private static function ciFile(): string
    {
        $yaml = @file_get_contents(self::CI_FILE);
        self::assertIsString($yaml, '.gitlab-ci.yml is missing.');

        return $yaml;
    }

    /**
     * The lines of the CI file.
     *
     * @return list<string>
     */
    private static function lines(): array
    {
        return preg_split('/\r\n|\r|\n/', self::ciFile()) ?: [];
    }

    /**
     * The body of one top-level job: from its key to the next top-level
     * key or the end of the file.
     */
    private static function jobBody(string $job): string
    {
        $lines = self::lines();
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^' . preg_quote($job, '/') . ':\s*$/u', $line) === 1) {
                $start = $i;

                break;
            }
        }
        if ($start === null) {
            self::fail("The {$job} job is missing from .gitlab-ci.yml.");
        }

        $body = [];
        foreach (array_slice($lines, $start + 1) as $line) {
            if (preg_match('/^[a-z][a-z0-9_-]*:/u', $line) === 1) {
                break; // the next top-level key
            }
            $body[] = $line;
        }

        return implode("\n", $body);
    }
}
