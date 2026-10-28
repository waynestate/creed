<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/refresh-patches.php against the saved feed fixtures in
 * tests/fixtures/feeds/ and checks what it writes to a throwaway policy.
 */
final class RefreshPatchesTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tmpDirs = [];
    }

    /**
     * Run the refresh script offline against the saved feeds.
     *
     * @param array<string, string> $feedOverrides feed file name => raw contents
     *
     * @return array{0: int, 1: string, 2: string, 3: string} exit code, stdout, stderr, policy path
     */
    private function runRefresh(string $policyFixture, array $feedOverrides = []): array
    {
        $root = dirname(__DIR__);
        $tmp = sys_get_temp_dir() . '/creed-refresh-' . uniqid('', true);
        $feedsDir = $tmp . '/feeds';
        if (!mkdir($feedsDir, 0777, true)) {
            self::fail('Could not create a temporary feeds directory');
        }
        $this->tmpDirs[] = $tmp;

        foreach (['php.json', 'laravel.json', 'node.json', 'endoflife-php.json'] as $name) {
            copy(__DIR__ . '/fixtures/feeds/' . $name, $feedsDir . '/' . $name);
        }
        foreach ($feedOverrides as $name => $contents) {
            file_put_contents($feedsDir . '/' . $name, $contents);
        }

        $policyPath = $tmp . '/policy.json';
        copy(__DIR__ . '/fixtures/' . $policyFixture, $policyPath);

        $cmd = [
            PHP_BINARY,
            $root . '/bin/refresh-patches.php',
            '--offline',
            $feedsDir,
            '--policy',
            $policyPath,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            self::fail('Could not start the refresh script');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr, $policyPath];
    }

    private function readPolicy(string $path): object
    {
        $raw = file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, false);
        if (!is_object($data)) {
            self::fail('The resulting policy is not a valid JSON object');
        }

        return $data;
    }

    private function versionEntry(object $policy, string $component, string $version): ?object
    {
        $components = $policy->components ?? null;
        if (!is_object($components)) {
            return null;
        }
        $componentObj = $components->{$component} ?? null;
        if (!is_object($componentObj)) {
            return null;
        }
        $versions = $componentObj->versions ?? null;
        if (!is_object($versions)) {
            return null;
        }
        $entry = $versions->{$version} ?? null;

        return is_object($entry) ? $entry : null;
    }

    /**
     * Read a string field from a version entry, or null when absent.
     */
    private function versionField(object $policy, string $component, string $version, string $field): ?string
    {
        $entry = $this->versionEntry($policy, $component, $version);
        if ($entry === null) {
            return null;
        }
        $vars = get_object_vars($entry);
        $value = $vars[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    #[Test]
    public function updateWritesLatestPatchAndCheckedDate(): void
    {
        [$exit, , , $policyPath] = $this->runRefresh('refresh-policy-update.json');
        self::assertSame(3, $exit, 'the policy had changes, so the script must exit 3');

        $policy = $this->readPolicy($policyPath);

        // PHP: 8.2 was stale, 8.3-8.5 had no latest_patch yet.
        self::assertSame('8.2.35', $this->versionField($policy, 'php', '8.2', 'latest_patch'));
        self::assertSame('8.3.36', $this->versionField($policy, 'php', '8.3', 'latest_patch'));
        self::assertSame('8.4.27', $this->versionField($policy, 'php', '8.4', 'latest_patch'));
        self::assertSame('8.5.12', $this->versionField($policy, 'php', '8.5', 'latest_patch'));
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            (string) ($this->versionField($policy, 'php', '8.2', 'latest_patch_checked') ?? ''),
        );

        // Laravel and Node: every version gets its latest patch.
        self::assertSame('10.50.4', $this->versionField($policy, 'laravel', '10', 'latest_patch'));
        self::assertSame('11.57.1', $this->versionField($policy, 'laravel', '11', 'latest_patch'));
        self::assertSame('12.70.2', $this->versionField($policy, 'laravel', '12', 'latest_patch'));
        self::assertSame('13.39.0', $this->versionField($policy, 'laravel', '13', 'latest_patch'));
        self::assertSame('20.20.2', $this->versionField($policy, 'node', '20', 'latest_patch'));
        self::assertSame('22.23.4', $this->versionField($policy, 'node', '22', 'latest_patch'));
        self::assertSame('24.22.1', $this->versionField($policy, 'node', '24', 'latest_patch'));
        self::assertSame('26.12.1', $this->versionField($policy, 'node', '26', 'latest_patch'));
    }

    #[Test]
    public function noChangeWhenPolicyIsCurrent(): void
    {
        [$exit, $stdout, , $policyPath] = $this->runRefresh('refresh-policy-current.json');
        self::assertSame(0, $exit, 'an up-to-date policy must exit 0');
        self::assertStringContainsString('No changes.', $stdout);
        self::assertSame(
            file_get_contents(__DIR__ . '/fixtures/refresh-policy-current.json'),
            file_get_contents($policyPath),
            'an up-to-date policy must not be rewritten',
        );
    }

    #[Test]
    public function versionMissingFromFeedIsLeftAloneWithWarning(): void
    {
        // 8.1 is not in the php.net fixture (it is EOL), so it must be left
        // alone and a warning printed, while the other versions are updated.
        [$exit, , $stderr, $policyPath] = $this->runRefresh('refresh-policy-update.json');
        self::assertSame(3, $exit);
        self::assertStringContainsString('php 8.1 not in feed', $stderr);

        $policy = $this->readPolicy($policyPath);
        self::assertSame('8.1.34', $this->versionField($policy, 'php', '8.1', 'latest_patch'), '8.1 must keep its old patch');
        self::assertSame('2025-12-18', $this->versionField($policy, 'php', '8.1', 'latest_patch_checked'), '8.1 must keep its old date');
    }

    #[Test]
    public function malformedFeedExitsOne(): void
    {
        [$exit, , $stderr] = $this->runRefresh(
            'refresh-policy-update.json',
            ['php.json' => "{ this is not valid json"],
        );
        self::assertSame(1, $exit, 'a malformed feed must exit 1');
        self::assertStringContainsString('php.net feed', $stderr);
    }

    /**
     * Recursively remove a directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
