<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/front-versions.php against a throwaway root (a small
 * policy/policy.json and a site/index.html written in this test) and checks
 * what it reports and writes. The real site/index.html is never touched.
 */
final class FrontVersionsTest extends TestCase
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
    public function checkFailsWhenAPatchIsStale(): void
    {
        $root = $this->makeRoot($this->policy(), $this->page());
        $before = (string) file_get_contents($root . '/site/index.html');

        [$exit, , $stderr] = $this->runScript($root, true);

        self::assertSame(1, $exit, 'a stale latest patch must fail the check');
        self::assertStringContainsString('front-versions: site/index.html Table 2-1 is stale; run composer site:versions', $stderr);
        self::assertStringContainsString('laravel 12 latest patch: page 12.71.0, policy 12.72.0', $stderr);
        self::assertSame($before, (string) file_get_contents($root . '/site/index.html'), '--check must not write');
    }

    #[Test]
    public function writeUpdatesPatchAndDates(): void
    {
        $page = str_replace(
            '<td>2026-08-13</td><td>2027-02-24</td><td>12.71.0</td>',
            '<td>2026-08-12</td><td>2027-02-23</td><td>12.71.0</td>',
            $this->page()
        );
        $root = $this->makeRoot($this->policy(), $page);

        [$exit, $stdout] = $this->runScript($root, false);

        self::assertSame(0, $exit, 'writing the table must succeed');
        self::assertStringContainsString('front-versions: site/index.html Table 2-1 updated (5 cells)', $stdout);
        $html = (string) file_get_contents($root . '/site/index.html');
        self::assertStringContainsString(
            '<tr data-c="laravel" data-v="12"><th scope="row">12</th><td>2026-08-13</td><td>2027-02-24</td><td>12.72.0</td><td><span class="st st--security">Security</span></td></tr>',
            $html
        );

        [$exit, $stdout] = $this->runScript($root, true);

        self::assertSame(0, $exit, 'the rewritten table must pass the check');
        self::assertStringContainsString('front-versions: site/index.html Table 2-1 is current', $stdout);
    }

    #[Test]
    public function aLineWithoutALatestPatchShowsNA(): void
    {
        $page = str_replace('<td>2032-04-30</td><td>n/a</td>', '<td>2032-04-30</td><td>8.4.0</td>', $this->page());
        $root = $this->makeRoot($this->policy(), $page);

        [$exit, , $stderr] = $this->runScript($root, true);

        self::assertSame(1, $exit, 'a typed-in patch where the policy has none must fail the check');
        self::assertStringContainsString('mysql 8.4 latest patch: page 8.4.0, policy n/a', $stderr);

        [$exit, $stdout] = $this->runScript($root, false);

        self::assertSame(0, $exit, 'writing the table must succeed');
        $html = (string) file_get_contents($root . '/site/index.html');
        self::assertStringContainsString(
            '<tr data-c="mysql" data-v="8.4"><th scope="row">8.4</th><td>2029-04-30</td><td>2032-04-30</td><td>n/a</td><td><span class="st st--active">Active</span></td></tr>',
            $html
        );

        [$exit, $stdout] = $this->runScript($root, true);

        self::assertSame(0, $exit, 'the rewritten table must pass the check');
        self::assertStringContainsString('front-versions: site/index.html Table 2-1 is current', $stdout);
    }

    #[Test]
    public function currentLinesAndGroupRowsAreLeftAlone(): void
    {
        // Make every row current except laravel 12's patch, so the rewrite
        // runs while the group headers and the colspan current line stand.
        $page = str_replace('<td>13.40.0</td>', '<td>13.42.0</td>', $this->page());
        $page = str_replace('<td>22.23.4</td>', '<td>22.23.5</td>', $page);
        $root = $this->makeRoot($this->policy(), $page);

        [$exit, $stdout] = $this->runScript($root, false);

        self::assertSame(0, $exit, 'writing the table must succeed');
        self::assertStringContainsString('front-versions: site/index.html Table 2-1 updated (1 cells)', $stdout);
        $html = (string) file_get_contents($root . '/site/index.html');
        $expected = str_replace('<td>12.71.0</td>', '<td>12.72.0</td>', $page);
        self::assertSame($expected, $html, 'only the stale cell may change; everything else stays byte-identical');
        self::assertStringContainsString(
            '<tr class="code-table__group"><th scope="rowgroup" colspan="5">Laravel <span>floor <b data-floor="laravel">12</b></span></th></tr>',
            $html
        );
        self::assertStringContainsString(
            '<tr data-c="vue" data-v="3"><th scope="row">Vue 3</th><td colspan="3">Current line: no published end dates, none invented.</td><td><span class="st st--active">Active</span></td></tr>',
            $html
        );
    }

    #[Test]
    public function anUnknownRowStops(): void
    {
        $unknown = '      <tr data-c="laravel" data-v="99"><th scope="row">99</th><td>2030-01-01</td><td>2031-01-01</td><td>99.0.0</td><td><span class="st st--active">Active</span></td></tr>' . "\n";
        $page = str_replace(
            '      <tr class="code-table__group"><th scope="rowgroup" colspan="5">Vue</th></tr>',
            $unknown . '      <tr class="code-table__group"><th scope="rowgroup" colspan="5">Vue</th></tr>',
            $this->page()
        );
        $root = $this->makeRoot($this->policy(), $page);
        $before = (string) file_get_contents($root . '/site/index.html');

        [$exit, , $stderr] = $this->runScript($root, false);

        self::assertSame(2, $exit, 'a row the policy does not know must stop the tool');
        self::assertStringContainsString('front-versions: Table 2-1 row laravel 99 is not in policy.json', $stderr);
        self::assertSame($before, (string) file_get_contents($root . '/site/index.html'), 'nothing may be written');
    }

    #[Test]
    public function aPageWithoutTheTableStops(): void
    {
        $page = "<!doctype html>\n<html lang=\"en\">\n<body>\n  <p>No table here.</p>\n</body>\n</html>\n";
        $root = $this->makeRoot($this->policy(), $page);

        [$exit, , $stderr] = $this->runScript($root, true);

        self::assertSame(2, $exit, 'a page without the table must stop the tool');
        self::assertStringContainsString('front-versions: site/index.html must have exactly one <table data-adopt>', $stderr);
    }

    /**
     * Create a throwaway root holding the fixture policy and page.
     */
    private function makeRoot(string $policy, string $page): string
    {
        $root = sys_get_temp_dir() . '/creed-front-versions-' . uniqid('', true);
        if (!mkdir($root . '/policy', 0777, true) || !mkdir($root . '/site', 0777, true)) {
            self::fail('Could not create a temporary root');
        }
        file_put_contents($root . '/policy/policy.json', $policy);
        file_put_contents($root . '/site/index.html', $page);
        $this->tmpDirs[] = $root;

        return $root;
    }

    /**
     * Run the front-versions script as a process against the given root.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runScript(string $root, bool $check): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__) . '/bin/front-versions.php', '--root=' . $root];
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
            self::fail('Could not start the front-versions script');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }

    private function policy(): string
    {
        return <<<'JSON'
{
    "components": {
        "laravel": {
            "versions": {
                "12": {"active_until": "2026-08-13", "security_until": "2027-02-24", "latest_patch": "12.72.0"},
                "13": {"active_until": "2027-09-30", "security_until": "2028-03-17", "latest_patch": "13.42.0"}
            }
        },
        "mysql": {
            "versions": {
                "8.4": {"active_until": "2029-04-30", "security_until": "2032-04-30"}
            }
        },
        "node": {
            "versions": {
                "22": {"active_until": "2025-10-21", "security_until": "2027-04-30", "latest_patch": "22.23.5"}
            }
        },
        "vue": {
            "versions": {
                "3": {"current": true}
            }
        }
    }
}
JSON;
    }

    private function page(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>CREED</title>
</head>
<body>
  <table data-adopt>
    <thead>
      <tr><th scope="col">Runtime</th><th scope="col">Active until</th><th scope="col">Security until</th><th scope="col">Latest patch</th><th scope="col">State</th></tr>
    </thead>
    <tbody>
      <tr class="code-table__group"><th scope="rowgroup" colspan="5">Laravel <span>floor <b data-floor="laravel">12</b></span></th></tr>
      <tr data-c="laravel" data-v="13"><th scope="row">13</th><td>2027-09-30</td><td>2028-03-17</td><td>13.40.0</td><td><span class="st st--active">Active</span></td></tr>
      <tr data-c="laravel" data-v="12"><th scope="row">12</th><td>2026-08-13</td><td>2027-02-24</td><td>12.71.0</td><td><span class="st st--security">Security</span></td></tr>
      <tr class="code-table__group"><th scope="rowgroup" colspan="5">Node <span>floor <b data-floor="node">22</b></span></th></tr>
      <tr data-c="node" data-v="22"><th scope="row">22</th><td>2025-10-21</td><td>2027-04-30</td><td>22.23.4</td><td><span class="st st--security">Security</span></td></tr>
      <tr class="code-table__group"><th scope="rowgroup" colspan="5">MySQL</th></tr>
      <tr data-c="mysql" data-v="8.4"><th scope="row">8.4</th><td>2029-04-30</td><td>2032-04-30</td><td>n/a</td><td><span class="st st--active">Active</span></td></tr>
      <tr class="code-table__group"><th scope="rowgroup" colspan="5">Vue</th></tr>
      <tr data-c="vue" data-v="3"><th scope="row">Vue 3</th><td colspan="3">Current line: no published end dates, none invented.</td><td><span class="st st--active">Active</span></td></tr>
    </tbody>
  </table>
</body>
</html>
HTML;
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
