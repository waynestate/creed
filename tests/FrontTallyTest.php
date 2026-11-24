<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs bin/front-tally.php against a throwaway root (a small BEST_PRACTICES.md
 * and a site/index.html written here) and checks what it reports and writes.
 * The real page is only ever read. FrontPageTallyTest holds the real page to
 * the record; this one holds the tool that keeps it there.
 *
 * Creed Bratton has kept two sets of books for years and swears both balance.
 * We keep one, and a script that balances it.
 */
final class FrontTallyTest extends TestCase
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
    public function theCommittedPageMatchesTheRecord(): void
    {
        [$exit, $stdout, $stderr] = $this->runScript(dirname(__DIR__), true);

        self::assertSame(0, $exit, "the committed front page must pass: {$stderr}");
        self::assertStringContainsString('front-tally: site/index.html counts match BEST_PRACTICES.md', $stdout);
    }

    #[Test]
    public function checkNamesEveryStaleCountAndWritesNothing(): void
    {
        $root = $this->makeRoot($this->record(true), $this->page());
        $before = (string) file_get_contents($root . '/site/index.html');

        [$exit, , $stderr] = $this->runScript($root, true);

        self::assertSame(1, $exit, 'a new practice and chapter must fail the check');
        self::assertStringContainsString('front-tally: site/index.html counts are stale against BEST_PRACTICES.md; run composer site:tally', $stderr);
        self::assertStringContainsString('meta description practice count: page 3, record 4', $stderr);
        self::assertStringContainsString('tally Documented count: page 1, record 2', $stderr);
        self::assertStringContainsString('contents heading: page Two chapters, 3 practices, record Three chapters, 4 practices', $stderr);
        self::assertStringContainsString('contents row 3: page missing, record Deprecation and removal', $stderr);
        self::assertStringContainsString('errata backlog count: page 1, record 2', $stderr);
        self::assertSame($before, (string) file_get_contents($root . '/site/index.html'), '--check must not write');
    }

    #[Test]
    public function writeAddsTheChapterRowAndRecounts(): void
    {
        $root = $this->makeRoot($this->record(true), $this->page());

        [$exit, $stdout] = $this->runScript($root, false);

        self::assertSame(0, $exit, 'writing the counts must succeed');
        self::assertStringContainsString('front-tally: site/index.html updated', $stdout);
        $html = (string) file_get_contents($root . '/site/index.html');
        self::assertStringContainsString('content="CREED: 4 numbered practices, and dates."', $html);
        self::assertStringContainsString('<dl class="tally" aria-label="How the 4 practices are held today">', $html);
        self::assertStringContainsString('<div class="tally__item tally__item--documented"><dt>Documented</dt><dd>2</dd></div>', $html);
        self::assertStringContainsString('<h2 id="contents-title">Three chapters, 4 practices</h2>', $html);
        self::assertStringContainsString('All 4 are in that file', $html);
        self::assertStringContainsString('The full backlog, 2 items, is at', $html);
        self::assertStringContainsString(
            '        <li><a href="https://github.com/waynestate/creed/blob/main/BEST_PRACTICES.md#3-deprecation-and-removal-d-174"><span class="toc__no">3</span><span class="toc__name">Deprecation and removal</span><span class="toc__lead" aria-hidden="true"></span><span class="toc__range">§3.1 to §3.1</span></a><span class="toc__bar" role="img" aria-label="1 documented"><i class="d" style="--n:1"></i></span></li>' . "\n      </ol>",
            $html,
        );
        // The rows it already had keep their own name and link.
        self::assertStringContainsString('<li><a href="#ch-1"><span class="toc__no">1</span><span class="toc__name">First things</span>', $html);

        [$exit, $stdout] = $this->runScript($root, true);

        self::assertSame(0, $exit, 'the rewritten page must pass the check');
        self::assertStringContainsString('front-tally: site/index.html counts match BEST_PRACTICES.md', $stdout);
    }

    #[Test]
    public function aCurrentPageIsLeftByteForByte(): void
    {
        $root = $this->makeRoot($this->record(false), $this->page());
        $before = (string) file_get_contents($root . '/site/index.html');

        [$exit, $stdout] = $this->runScript($root, false);

        self::assertSame(0, $exit);
        self::assertStringContainsString('counts match BEST_PRACTICES.md', $stdout);
        self::assertSame($before, (string) file_get_contents($root . '/site/index.html'));
    }

    #[Test]
    public function anUnknownStampStopsTheTool(): void
    {
        $record = str_replace('How the stack holds it: `Checked`.', 'How the stack holds it: `Vibes`.', $this->record(false));
        $root = $this->makeRoot($record, $this->page());

        [$exit, , $stderr] = $this->runScript($root, true);

        self::assertSame(2, $exit);
        self::assertStringContainsString('practice 2 in BEST_PRACTICES.md has an unknown stamp `Vibes`', $stderr);
    }

    private function record(bool $withChapterThree): string
    {
        $text = <<<'MD'
            # CREED best practices

            ## 1. First things

            ### 1. Keep dates in one file

            How the stack holds it: `Enforced`. A test fails.

            ### 2. Name the check

            How the stack holds it: `Checked`. PROCTOR warns.

            ## 2. Second things (second pass)

            ### 3. Write it down

            How the stack holds it: `Documented`. The handbook says so.

            MD;
        if ($withChapterThree) {
            $text .= <<<'MD'

                ## 3. Deprecation and removal (D-174)

                ### 4. Announce, then wait

                How the stack holds it: `Documented`. The handbook says so.

                MD;
        }
        $text .= <<<'MD'

            ## Gaps for the backlog

            1. A gap.

            MD;
        if ($withChapterThree) {
            $text .= "2. Another gap.\n";
        }

        return $text . "\n## Sources\n\nNone.\n";
    }

    private function page(): string
    {
        return <<<'HTML'
            <!doctype html>
            <meta name="description" content="CREED: 3 numbered practices, and dates.">
            <dl class="tally" aria-label="How the 3 practices are held today">
              <div class="tally__item tally__item--enforced"><dt>Enforced</dt><dd>1</dd></div>
              <div class="tally__item tally__item--checked"><dt>Checked</dt><dd>1</dd></div>
              <div class="tally__item tally__item--partially"><dt>Partially</dt><dd>0</dd></div>
              <div class="tally__item tally__item--documented"><dt>Documented</dt><dd>1</dd></div>
              <div class="tally__item tally__item--notyet"><dt>Not yet</dt><dd>0</dd></div>
            </dl>
            <h2 id="contents-title">Two chapters, 3 practices</h2>
            <p>This page prints a few. All 3 are in that file.</p>
            <ol class="toc" data-reveal>
                    <li><a href="#ch-1"><span class="toc__no">1</span><span class="toc__name">First things</span><span class="toc__lead" aria-hidden="true"></span><span class="toc__range">§1.1 to §1.2</span></a><span class="toc__bar" role="img" aria-label="1 enforced, 1 checked"><i class="e" style="--n:1"></i><i class="c" style="--n:1"></i></span></li>
                    <li><a href="#ch-2"><span class="toc__no">2</span><span class="toc__name">Second things</span><span class="toc__lead" aria-hidden="true"></span><span class="toc__range">§2.1 to §2.1</span></a><span class="toc__bar" role="img" aria-label="1 documented"><i class="d" style="--n:1"></i></span></li>
                  </ol>
            <p class="errata__foot">The full backlog, 1 items, is at the end.</p>

            HTML;
    }

    private function makeRoot(string $record, string $page): string
    {
        $root = sys_get_temp_dir() . '/creed-front-tally-' . uniqid('', true);
        if (!mkdir($root . '/site', 0777, true)) {
            self::fail('Could not create a temporary root');
        }
        file_put_contents($root . '/BEST_PRACTICES.md', $record);
        file_put_contents($root . '/site/index.html', $page);
        $this->tmpDirs[] = $root;

        return $root;
    }

    /**
     * Run the front-tally script as a process against the given root.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runScript(string $root, bool $check): array
    {
        $cmd = [PHP_BINARY, dirname(__DIR__) . '/bin/front-tally.php', '--root=' . $root];
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
            self::fail('Could not start the front-tally script');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
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
