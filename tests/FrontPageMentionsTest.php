<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/front-mentions.php against the committed front page and against
 * throwaway roots (copies of site/index.html, site/index.md and
 * site/version-mentions.txt written under the system temp dir) and checks
 * what it reports and writes. The real site files are never touched.
 */
final class FrontPageMentionsTest extends TestCase
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

    #[Test]
    public function theCommittedAllowlistMatchesTheFrontPage(): void
    {
        [$exit, , $stderr] = $this->runScript(dirname(__DIR__), true);

        self::assertSame(0, $exit, 'site/version-mentions.txt and the front page disagree:' . "\n" . $stderr);
    }

    #[Test]
    public function checkFailsOnAnUnlistedMention(): void
    {
        $root = $this->makeRoot();
        $html = (string) file_get_contents($root . '/site/index.html');
        $planted = str_replace('since PROCTOR 0.10.0', 'since PROCTOR 0.10.99', $html);
        self::assertNotSame($html, $planted, 'the planted version was not found in site/index.html');
        file_put_contents($root . '/site/index.html', $planted);

        [$exit, , $stderr] = $this->runScript($root, true);

        self::assertSame(1, $exit, 'an unlisted mention must fail the check');
        self::assertStringContainsString('unlisted mention "PROCTOR 0.10.99"', $stderr);
        self::assertStringContainsString('verify the version is correct', $stderr);
        self::assertStringContainsString('update site/version-mentions.txt', $stderr);
    }

    #[Test]
    public function checkFailsOnAStaleEntry(): void
    {
        $root = $this->makeRoot();
        $list = (string) file_get_contents($root . '/site/version-mentions.txt');
        self::assertStringNotContainsString("Squire 0.3.0\n", $list, 'the planted entry must not already be listed');
        file_put_contents($root . '/site/version-mentions.txt', $list . "Squire 0.3.0\n");

        [$exit, , $stderr] = $this->runScript($root, true);

        self::assertSame(1, $exit, 'a listed entry with no mention on the page must fail the check');
        self::assertStringContainsString('"Squire 0.3.0" has no mention on the front page', $stderr);
    }

    #[Test]
    public function writeRegeneratesTheAllowlist(): void
    {
        $root = $this->makeRoot();
        $html = (string) file_get_contents($root . '/site/index.html');
        $planted = str_replace('since PROCTOR 0.10.0', 'since PROCTOR 0.10.99', $html);
        self::assertNotSame($html, $planted, 'the planted version was not found in site/index.html');
        file_put_contents($root . '/site/index.html', $planted);

        [$exit, $stdout] = $this->runScript($root, false);

        self::assertSame(0, $exit, 'regenerating the allowlist must succeed: ' . $stdout);
        self::assertStringContainsString('site/version-mentions.txt rewritten', $stdout);
        $list = (string) file_get_contents($root . '/site/version-mentions.txt');
        self::assertStringContainsString("PROCTOR 0.10.99\n", $list, 'the new mention must be listed');
        self::assertStringContainsString("PROCTOR 0.10.0\n", $list, 'a mention still on the page must stay listed');
    }

    /**
     * Create a throwaway root holding copies of the committed front page
     * files.
     */
    private function makeRoot(): string
    {
        $root = sys_get_temp_dir() . '/creed-front-mentions-' . uniqid('', true);
        if (!mkdir($root . '/site', 0777, true)) {
            self::fail('Could not create a temporary root');
        }
        foreach (['index.html', 'index.md', 'version-mentions.txt'] as $file) {
            $source = dirname(__DIR__) . '/site/' . $file;
            if (!is_file($source) || !copy($source, $root . '/site/' . $file)) {
                self::fail("Could not copy {$source} into the temporary root");
            }
        }
        $this->tmpDirs[] = $root;

        return $root;
    }

    /**
     * Run the front-mentions script as a process against the given root.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runScript(string $root, bool $check): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__) . '/bin/front-mentions.php', '--root=' . $root];
        if ($check) {
            $cmd[] = '--check';
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            self::fail('Could not start the front-mentions script');
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
