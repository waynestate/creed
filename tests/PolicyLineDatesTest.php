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
 * Covers bin/validate-policy.php's support-date check: every line carries
 * security_until or "current": true, exactly one of them, and the message
 * names the component, the version and the fix.
 *
 * The schema already rejects these lines, but with a message that names
 * nothing ("The properties must match schema"). An undated line is not a
 * cosmetic slip: the reader refuses to guess its state, so oldestSupported()
 * throws for the whole component, which is why CI stops it here.
 */
final class PolicyLineDatesTest extends TestCase
{
    /**
     * Run bin/validate-policy.php against one fixture policy.
     *
     * @return array{0: int, 1: string} exit code, stdout
     */
    private function runValidator(string $fixture): array
    {
        $root = dirname(__DIR__);
        $cmd = [PHP_BINARY, $root . '/bin/validate-policy.php', '--policy', $root . '/tests/fixtures/' . $fixture];
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::fail('Could not start the validator');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function undatedLines(): array
    {
        return [
            'no current, no security_until' => ['no-security-until.json', 'php 9.9:'],
            'current false, no security_until' => ['current-false-no-security-until.json', 'node 30:'],
        ];
    }

    #[Test]
    #[DataProvider('undatedLines')]
    public function anUndatedLineIsRejectedByName(string $fixture, string $line): void
    {
        [$exit, $stdout] = $this->runValidator($fixture);

        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString($line . ' the line has neither security_until nor "current": true', $stdout);
        self::assertStringContainsString('add security_until', $stdout);
        self::assertStringContainsString('or mark the line "current": true', $stdout);
        self::assertStringNotContainsString('must match schema', $stdout);
    }

    #[Test]
    public function aCurrentLineWithADateIsRejectedByName(): void
    {
        [$exit, $stdout] = $this->runValidator('current-with-security-until.json');

        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString('vue 3: a line marked "current": true carries no security_until', $stdout);
        self::assertStringContainsString('remove security_until, or drop "current": true', $stdout);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function datedLines(): array
    {
        return [
            'dated lines' => ['good.json'],
            'current line without a date' => ['current-only.json'],
        ];
    }

    #[Test]
    #[DataProvider('datedLines')]
    public function datedAndCurrentLinesPass(string $fixture): void
    {
        [$exit, $stdout] = $this->runValidator($fixture);

        self::assertSame(0, $exit, $stdout);
        self::assertStringNotContainsString('security_until', $stdout);
    }

    #[Test]
    public function everyBundledLineIsDatedOrCurrent(): void
    {
        $policy = Policy::bundled();
        $on = new DateTimeImmutable('2026-11-09');
        foreach ($policy->components() as $component) {
            // Throws on an undated line; the validator exists so it never does.
            $policy->supported($component, $on);
            foreach ($policy->versions($component) as $version) {
                $line = $policy->line($component, $version);
                self::assertNotNull($line);
                self::assertTrue(
                    ($line['current'] === true) !== ($line['security_until'] !== null),
                    "{$component} {$version} must carry security_until or \"current\": true, exactly one.",
                );
            }
        }
    }

    #[Test]
    public function theReaderStillRefusesAnUndatedLine(): void
    {
        // The runtime is left alone on purpose: the reader does not guess, and
        // the validator is where an undated line is stopped.
        $policy = Policy::fromFile(__DIR__ . '/fixtures/no-security-until.json');

        $this->expectException(InvalidArgumentException::class);
        $policy->oldestSupported('php', new DateTimeImmutable('2026-11-09'));
    }
}
