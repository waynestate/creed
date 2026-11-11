<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Composer dist: what a consumer actually receives from the tag
 * archive. bin/export.php ships so a consumer runs its --check from
 * vendor/waynestate/creed/bin/export.php; the rest of bin/ stays
 * maintainer-only.
 */
final class DistPackagingTest extends TestCase
{
    private const NS = 'Waynestate\\Proctor\\Creed';

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
     * A fresh temporary directory, registered for cleanup.
     */
    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/creed-dist-' . uniqid('', true);
        if (!mkdir($dir, 0777, true)) {
            self::fail('Could not create a temporary directory');
        }
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    /**
     * Run a command with the given arguments.
     *
     * @param list<string> $cmd
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCmd(array $cmd, ?string $cwd = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return [127, '', 'could not start ' . $cmd[0]];
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
     * Run PHP with the given arguments.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runPhp(array $args): array
    {
        return $this->runCmd(array_merge([PHP_BINARY], $args));
    }

    /**
     * Run the export script from a dist-shaped copy with the given arguments.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runDistExport(string $dist, array $args): array
    {
        return $this->runPhp(array_merge([$dist . '/bin/export.php'], $args));
    }

    #[Test]
    public function gitattributesIgnoresEveryBinScriptExceptExport(): void
    {
        $root = dirname(__DIR__);
        $raw = file_get_contents($root . '/.gitattributes');
        self::assertNotFalse($raw, '.gitattributes must exist at the repository root');
        $lines = $this->attributeLines($raw);

        self::assertFalse(
            $this->isExportIgnored($lines, '/bin'),
            'the /bin directory must not be export-ignored whole; git archive would skip every file in it',
        );

        $binFiles = glob($root . '/bin/*.php') ?: [];
        self::assertNotEmpty($binFiles, 'bin/ holds no PHP files; the glob in this test is broken');
        foreach ($binFiles as $file) {
            $name = basename($file);
            if ($name === 'export.php') {
                continue;
            }
            self::assertTrue(
                $this->isExportIgnored($lines, '/bin/' . $name),
                "bin/{$name} is a maintainer tool and needs its own export-ignore line in .gitattributes",
            );
        }

        self::assertFalse(
            $this->isExportIgnored($lines, '/bin/export.php'),
            'bin/export.php ships in the dist and must not be export-ignored',
        );
    }

    #[Test]
    public function theTagArchiveHoldsOnlyExportFromBin(): void
    {
        $root = dirname(__DIR__);
        [$exit, $stdout, $stderr] = $this->runCmd(['git', 'rev-parse', '--is-inside-work-tree'], $root);
        if ($exit !== 0 || trim($stdout) !== 'true') {
            $this->markTestSkipped('git work tree unavailable, cannot build the tag archive: ' . trim($stderr));
        }

        [$exit, , $stderr] = $this->runCmd(['tar', '--version'], $root);
        if ($exit !== 0) {
            $this->markTestSkipped('tar unavailable, cannot list the tag archive: ' . trim($stderr));
        }

        $archive = $this->tmpDir() . '/dist.tar';
        [$exit, , $stderr] = $this->runCmd(['git', 'archive', '--worktree-attributes', 'HEAD', '-o', $archive], $root);
        self::assertSame(0, $exit, "git archive failed: {$stderr}");

        [$exit, $stdout, $stderr] = $this->runCmd(['tar', '-tf', $archive], $root);
        self::assertSame(0, $exit, "tar -tf failed: {$stderr}");

        $entries = array_values(array_filter(
            array_map('trim', explode("\n", $stdout)),
            static fn(string $entry): bool => $entry !== '',
        ));
        self::assertContains('bin/export.php', $entries, 'the dist archive must contain bin/export.php');

        $binEntries = array_values(array_filter(
            $entries,
            static fn(string $entry): bool => str_starts_with($entry, 'bin/'),
        ));
        self::assertSame(
            ['bin/export.php'],
            $binEntries,
            'no other bin/ file may reach the dist archive',
        );
    }

    #[Test]
    public function theExportRunsFromADistShapedCopy(): void
    {
        $root = dirname(__DIR__);
        $dist = $this->tmpDir();

        self::assertNotFalse(mkdir($dist . '/bin'));
        self::assertNotFalse(copy($root . '/bin/export.php', $dist . '/bin/export.php'));
        $this->copyDir($root . '/src', $dist . '/src');
        self::assertNotFalse(mkdir($dist . '/policy'));
        self::assertNotFalse(copy($root . '/policy/policy.json', $dist . '/policy/policy.json'));
        self::assertNotFalse(copy($root . '/composer.json', $dist . '/composer.json'));

        $out = $this->tmpDir();
        [$exit, , $stderr] = $this->runDistExport($dist, ['--namespace=' . self::NS, '--to=' . $out]);
        self::assertSame(0, $exit, "the export must run from a dist-shaped copy: {$stderr}");

        [$exit, , $stderr] = $this->runDistExport($dist, ['--check', '--namespace=' . self::NS, '--to=' . $out]);
        self::assertSame(0, $exit, "the consumer --check must pass against its own export: {$stderr}");

        $manifest = json_decode((string) file_get_contents($out . '/manifest.json'), true);
        self::assertIsArray($manifest, 'the export must write a manifest.json');
        self::assertSame(
            'unknown',
            $manifest['creed_version'],
            'without a git checkout the manifest records unknown and --check ignores it',
        );
    }

    /**
     * The non-comment lines of .gitattributes as path plus attributes.
     *
     * @return list<array{path: string, attrs: list<string>}>
     */
    private function attributeLines(string $raw): array
    {
        $lines = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim(str_replace("\r", '', $line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            self::assertNotFalse($parts, "unparseable .gitattributes line: {$line}");
            $lines[] = ['path' => $parts[0], 'attrs' => array_slice($parts, 1)];
        }

        return $lines;
    }

    /**
     * True when the parsed lines carry an export-ignore for the exact path.
     *
     * @param list<array{path: string, attrs: list<string>}> $lines
     */
    private function isExportIgnored(array $lines, string $path): bool
    {
        foreach ($lines as $line) {
            if ($line['path'] === $path && in_array('export-ignore', $line['attrs'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy a directory tree.
     */
    private function copyDir(string $from, string $to): void
    {
        self::assertTrue(is_dir($from), "missing source directory: {$from}");
        self::assertNotFalse(mkdir($to, 0777, true));
        foreach (scandir($from) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $source = $from . '/' . $item;
            if (is_dir($source)) {
                $this->copyDir($source, $to . '/' . $item);
            } else {
                self::assertNotFalse(copy($source, $to . '/' . $item));
            }
        }
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
