<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests\Refresh;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * bin/refresh-policy.php end to end, offline, on a throwaway copy of the
 * policy.
 */
final class RefreshPolicyCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/creed-refresh-policy-' . uniqid('', true);
        mkdir($this->tmp, 0777, true);

        // The real policy minus the composer minimum, which upstream already
        // calls EOL and which would keep every run at exit 4.
        $policy = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/policy/policy.json'), false);
        self::assertIsObject($policy);
        unset($policy->tools);
        file_put_contents($this->tmp . '/policy.json', json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }

    /** @return array{0: int, 1: string} */
    private function exec(string ...$extra): array
    {
        $cmd = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/refresh-policy.php',
            '--offline', dirname(__DIR__) . '/fixtures/feeds',
            '--policy', $this->tmp . '/policy.json',
            '--sources', $this->tmp . '/sources.json',
            ...$extra,
        ];
        $process = proc_open(array_values($cmd), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }

    #[Test]
    public function touchWithNoChangesExitsThreeAndOnlyMovesRefreshedOn(): void
    {
        // Settle first: the recorded feeds and the committed policy agree, so
        // a second run has nothing to do.
        $this->exec('--today', '2026-10-29');
        [$exit] = $this->exec('--today', '2026-10-29');
        self::assertSame(0, $exit);

        $before = (string) file_get_contents($this->tmp . '/policy.json');
        [$exit, $out] = $this->exec('--today', '2026-11-05', '--touch');
        self::assertSame(3, $exit, $out);

        $after = (string) file_get_contents($this->tmp . '/policy.json');
        self::assertSame(
            preg_replace('/"refreshed_on": "[^"]+"/', 'X', $before),
            preg_replace('/"refreshed_on": "[^"]+"/', 'X', $after),
        );
        self::assertStringContainsString('"refreshed_on": "2026-11-05"', $after);
    }

    #[Test]
    public function aReleasedLoosenFailExitsThreeAndKeepsTheAlertInTheReport(): void
    {
        // Roll php 8.4's security date back one year: php.net/states and
        // endoflife.date/php then agree on the later date, which releases
        // the loosen-fail instead of holding it.
        $policy = json_decode((string) file_get_contents($this->tmp . '/policy.json'), true);
        self::assertIsArray($policy);
        $policy['components']['php']['versions']['8.4']['security_until'] = '2027-12-31';
        file_put_contents($this->tmp . '/policy.json', json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        [$exit, $out] = $this->exec('--today', '2026-10-29', '--report', $this->tmp . '/report.json');

        self::assertSame(3, $exit, $out);
        $written = json_decode((string) file_get_contents($this->tmp . '/policy.json'), true);
        self::assertSame('2028-12-31', $written['components']['php']['versions']['8.4']['security_until']);
        $report = json_decode((string) file_get_contents($this->tmp . '/report.json'), true);
        self::assertSame([], $report['held']);
        self::assertSame([], $report['refused']);
        self::assertCount(1, $report['changes']);
        self::assertSame('loosen-fail', $report['changes'][0]['class']);
        self::assertSame('php', $report['changes'][0]['component']);
        self::assertSame('8.4', $report['changes'][0]['line']);
        self::assertSame('security_until', $report['changes'][0]['field']);
        self::assertSame('2028-12-31', $report['changes'][0]['new']);
        self::assertSame(['php.net/states', 'endoflife.date/php'], $report['changes'][0]['sources']);
    }

    #[Test]
    public function dryRunWritesNothingAndAlwaysExitsZero(): void
    {
        $before = (string) file_get_contents($this->tmp . '/policy.json');
        [$exit] = $this->exec('--today', '2026-10-29', '--dry-run');

        self::assertSame(0, $exit);
        self::assertSame($before, file_get_contents($this->tmp . '/policy.json'));
        self::assertFileDoesNotExist($this->tmp . '/sources.json');
    }

    #[Test]
    public function anUnreadablePolicyIsAHardFailure(): void
    {
        unlink($this->tmp . '/policy.json');
        [$exit] = $this->exec('--today', '2026-10-29');

        self::assertSame(1, $exit);
    }
}
