<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Policy;
use Waynestate\Creed\Refresh\Refresher;
use Waynestate\Creed\Refresh\SourceLedger;
use Waynestate\Creed\Refresh\Sources;

/**
 * Covers the optional minimum_patch field: the Policy::minimumPatch()
 * reader, the bin/validate-policy.php checks, and the refresh script
 * keeping the field when it rewrites a policy.
 */
final class PolicyMinimumPatchTest extends TestCase
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

    private function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Run bin/validate-policy.php against one fixture policy.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runValidator(string $fixture): array
    {
        $cmd = [
            PHP_BINARY,
            $this->root() . '/bin/validate-policy.php',
            '--policy',
            $this->root() . '/tests/fixtures/' . $fixture,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            self::fail('Could not start the validator');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }

    /**
     * Run the refresh script offline against the saved feeds, like
     * RefreshPatchesTest does.
     *
     * @return array{0: int, 1: string, 2: string, 3: string} exit code, stdout, stderr, policy path
     */
    private function runRefresh(string $policyFixture): array
    {
        $root = $this->root();
        $tmp = sys_get_temp_dir() . '/creed-minimum-patch-' . uniqid('', true);
        $feedsDir = $tmp . '/feeds';
        if (!mkdir($feedsDir, 0777, true)) {
            self::fail('Could not create a temporary feeds directory');
        }
        $this->tmpDirs[] = $tmp;

        foreach (['php.json', 'laravel.json', 'node.json', 'endoflife-php.json', 'endoflife-vue.json'] as $name) {
            copy($root . '/tests/fixtures/feeds/' . $name, $feedsDir . '/' . $name);
        }

        $policyPath = $tmp . '/policy.json';
        copy($root . '/tests/fixtures/' . $policyFixture, $policyPath);

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

    /**
     * Read a string field from a version entry, or null when absent.
     */
    private function versionField(object $policy, string $component, string $version, string $field): ?string
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
        if (!is_object($entry)) {
            return null;
        }
        $vars = get_object_vars($entry);
        $value = $vars[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
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

    #[Test]
    public function minimumPatchReadsTheField(): void
    {
        $policy = Policy::fromFile($this->root() . '/tests/fixtures/with-minimum-patch.json');
        self::assertSame('8.4.1', $policy->minimumPatch('php', '8.4'));
    }

    #[Test]
    public function minimumPatchIsNullWhenAbsentOrUnknown(): void
    {
        $policy = Policy::fromFile($this->root() . '/tests/fixtures/with-minimum-patch.json');
        self::assertNull($policy->minimumPatch('php', '8.3'), '8.3 has no minimum_patch');
        self::assertNull($policy->minimumPatch('php', '9.9'), 'an unknown version gives null, not an exception');
        self::assertNull($policy->minimumPatch('ruby', '3.2'), 'an unknown component gives null, not an exception');
    }

    #[Test]
    public function validatorAcceptsAValidMinimumPatch(): void
    {
        [$exit, $stdout] = $this->runValidator('with-minimum-patch.json');
        self::assertSame(0, $exit, $stdout);
    }

    #[Test]
    public function validatorRejectsAMinimumPatchFromAnotherLine(): void
    {
        [$exit, $stdout] = $this->runValidator('minimum-patch-wrong-line.json');
        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString('php 8.4: minimum_patch 8.5.1 is not in the 8.4 line', $stdout);
    }

    #[Test]
    public function validatorRejectsAMinimumPatchAboveLatestPatch(): void
    {
        [$exit, $stdout] = $this->runValidator('minimum-patch-above-latest.json');
        self::assertSame(1, $exit, $stdout);
        self::assertStringContainsString('php 8.4: minimum_patch 8.4.30 is above latest_patch 8.4.27', $stdout);
    }

    #[Test]
    public function refreshKeepsMinimumPatch(): void
    {
        [$exit, $stdout, $stderr, $policyPath] = $this->runRefresh('refresh-policy-minimum-patch.json');
        self::assertSame(3, $exit, "the policy had changes, so the script must exit 3: {$stdout} {$stderr}");
        $policy = $this->readPolicy($policyPath);
        self::assertSame('8.2.0', $this->versionField($policy, 'php', '8.2', 'minimum_patch'));
        foreach (['8.1', '8.3', '8.4', '8.5'] as $line) {
            self::assertNull($this->versionField($policy, 'php', $line, 'minimum_patch'), "the refresh must never add a minimum_patch ({$line})");
        }
    }

    #[Test]
    public function validatorAcceptsAMajorOnlyLineAndAFloorEqualToLatestPatch(): void
    {
        [$exit, $stdout] = $this->runValidator('minimum-patch-major-line.json');
        self::assertSame(0, $exit, $stdout);
    }

    #[Test]
    public function refresherKeepsMinimumPatchNextToLatestPatchAndNeverAddsOne(): void
    {
        $policy = json_decode(<<<'JSON'
{
    "schema_version": 1,
    "components": {
        "php": {"versions": {
            "8.3": {"active_until": "2025-12-31", "security_until": "2027-12-31", "latest_patch": "8.3.1", "latest_patch_checked": "2026-10-01", "minimum_patch": "8.3.0"},
            "8.4": {"active_until": "2026-12-31", "security_until": "2028-12-31", "latest_patch": "8.4.1", "latest_patch_checked": "2026-10-01"}
        }}
    },
    "rules": {"eol_grace_days": 90}
}
JSON, false);

        $sources = new Sources(phpActive: ['8.3' => '8.3.2', '8.4' => '8.4.2']);
        (new Refresher('2026-10-29'))->refresh($policy, $sources, SourceLedger::empty());

        self::assertSame('8.3.2', $this->versionField($policy, 'php', '8.3', 'latest_patch'), 'the patch bump was applied');
        self::assertSame('8.3.0', $this->versionField($policy, 'php', '8.3', 'minimum_patch'));
        $line = $policy->components->php->versions->{'8.3'};
        self::assertIsObject($line);
        self::assertSame(
            ['active_until', 'security_until', 'latest_patch', 'minimum_patch', 'latest_patch_checked'],
            array_keys(get_object_vars($line)),
            'minimum_patch stays next to latest_patch when the line is rewritten',
        );
        self::assertSame('8.4.2', $this->versionField($policy, 'php', '8.4', 'latest_patch'));
        self::assertNull($this->versionField($policy, 'php', '8.4', 'minimum_patch'), 'the refresh must never add a minimum_patch');
    }
}
