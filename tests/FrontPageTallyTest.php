<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the front page's numbers honest. BEST_PRACTICES.md is the record: each
 * practice ends with "How the stack holds it: `Tag`". The front page prints the
 * same facts four ways (the tally on the title page, the contents heading, the
 * stamp bars beside each chapter, and the stamp on every clause it reprints),
 * and this test fails the moment any of them drifts from the record.
 *
 * Creed Bratton once said he'd been "involved in a number of cults, both as a
 * leader and a follower." This book has one count, and the front page follows.
 */
final class FrontPageTallyTest extends TestCase
{
    private const PRACTICES = __DIR__ . '/../BEST_PRACTICES.md';
    private const FRONT = __DIR__ . '/../site/index.html';

    /** Stamp tag => the short class the contents bars use, in printing order. */
    private const BAR = ['Enforced' => 'e', 'Checked' => 'c', 'Partially' => 'p', 'Documented' => 'd', 'Not yet' => 'n'];

    /** Stamp tag => the modifier the tally and the stamps use. */
    private const MODIFIER = [
        'Enforced' => 'enforced',
        'Checked' => 'checked',
        'Partially' => 'partially',
        'Documented' => 'documented',
        'Not yet' => 'notyet',
    ];

    private const WORDS = [
        1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen', 'Twenty',
    ];

    #[Test]
    public function everyPracticeCarriesAKnownStamp(): void
    {
        $chapters = self::chapters();
        self::assertNotEmpty($chapters, 'BEST_PRACTICES.md has no numbered chapters.');
        foreach ($chapters as $no => $tags) {
            self::assertNotEmpty($tags, "Chapter {$no} has no practices.");
            foreach ($tags as $i => $tag) {
                self::assertArrayHasKey($tag, self::BAR, "§{$no}." . ($i + 1) . " has an unknown stamp `{$tag}`.");
            }
        }
    }

    #[Test]
    public function theTallyMatchesTheRecord(): void
    {
        $totals = self::totals();
        $html = self::front();
        $practices = array_sum($totals);

        foreach (self::MODIFIER as $tag => $modifier) {
            $pattern = '~<div class="tally__item tally__item--' . $modifier . '"><dt>' . preg_quote($tag, '~') . '</dt><dd>(\d+)</dd></div>~';
            self::assertSame(1, preg_match($pattern, $html, $m), "The tally has no {$tag} line.");
            self::assertSame($totals[$tag] ?? 0, (int) $m[1], "The tally's {$tag} count drifted from BEST_PRACTICES.md.");
        }
        self::assertStringContainsString("How the {$practices} practices are held today", $html);
        self::assertStringContainsString("{$practices} numbered practices", $html, 'The meta description states the wrong count.');
    }

    #[Test]
    public function theContentsHeadingCountsChaptersAndPractices(): void
    {
        $chapters = self::chapters();
        $practices = array_sum(array_map('count', $chapters));
        $word = self::WORDS[count($chapters)] ?? (string) count($chapters);

        self::assertStringContainsString(
            "<h2 id=\"contents-title\">{$word} chapters, {$practices} practices</h2>",
            self::front(),
        );
    }

    #[Test]
    public function everyChapterHasAContentsRowWithTheRightBar(): void
    {
        $html = self::front();
        preg_match_all('~<li><a href="[^"]*"><span class="toc__no">(\d+)</span>.*?<span class="toc__range">([^<]*)</span></a>(<span class="toc__bar"[^>]*>.*?</span>)</li>~', $html, $rows, PREG_SET_ORDER);
        $byChapter = [];
        foreach ($rows as $row) {
            $byChapter[(int) $row[1]] = ['range' => $row[2], 'bar' => $row[3]];
        }

        $chapters = self::chapters();
        self::assertSame(array_keys($chapters), array_keys($byChapter), 'The contents rows are not the chapters of BEST_PRACTICES.md.');

        foreach ($chapters as $no => $tags) {
            $count = array_count_values($tags);
            $label = [];
            $segments = '';
            foreach (self::BAR as $tag => $class) {
                if (isset($count[$tag])) {
                    $label[] = $count[$tag] . ' ' . strtolower($tag);
                    $segments .= '<i class="' . $class . '" style="--n:' . $count[$tag] . '"></i>';
                }
            }
            $expected = '<span class="toc__bar" role="img" aria-label="' . implode(', ', $label) . '">' . $segments . '</span>';
            self::assertSame($expected, $byChapter[$no]['bar'], "Chapter {$no}'s stamp bar drifted from BEST_PRACTICES.md.");
            self::assertSame("§{$no}.1 to §{$no}." . count($tags), $byChapter[$no]['range'], "Chapter {$no}'s clause range is wrong.");
        }
    }

    #[Test]
    public function theKeyExplainsEveryStamp(): void
    {
        preg_match_all('~<dt><span class="stamp stamp--([a-z]+)">([^<]+)</span></dt>~', self::front(), $m);
        self::assertSame(array_values(self::MODIFIER), $m[1], 'The key to the stamps does not list the five stamps in order.');
        self::assertSame(array_keys(self::MODIFIER), $m[2]);
    }

    #[Test]
    public function everyReprintedClauseWearsItsStamp(): void
    {
        $chapters = self::chapters();
        $html = self::front();
        $found = preg_match_all('~<div class="clause" id="c-(\d+)-(\d+)"[^>]*>.*?<span class="stamp stamp--([a-z]+)">([^<]+)</span>~s', $html, $clauses, PREG_SET_ORDER);
        self::assertGreaterThan(0, $found, 'The front page reprints no clauses.');

        foreach ($clauses as [, $chapter, $clause, $modifier, $word]) {
            $tag = $chapters[(int) $chapter][(int) $clause - 1] ?? null;
            self::assertNotNull($tag, "§{$chapter}.{$clause} is not in BEST_PRACTICES.md.");
            self::assertSame(self::MODIFIER[$tag], $modifier, "§{$chapter}.{$clause} is stamped {$word}; BEST_PRACTICES.md says {$tag}.");
            self::assertSame($tag, $word);
        }
    }

    /**
     * Stamp tags per chapter, in practice order: [chapter number => list of tags].
     *
     * @return array<int, list<string>>
     */
    private static function chapters(): array
    {
        $text = (string) file_get_contents(self::PRACTICES);
        $chapters = [];
        $chapter = null;
        $inPractice = false;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $heading = preg_match('/^#{2,3} /', $line) === 1;
            if ($heading && $inPractice && $chapter !== null) {
                $chapters[$chapter][] = 'no stamp'; // a practice without its line fails the first test
                $inPractice = false;
            }
            if (preg_match('/^## (\d+)\. /', $line, $m) === 1) {
                $chapter = (int) $m[1];
                $chapters[$chapter] = [];
            } elseif (str_starts_with($line, '## ')) {
                $chapter = null; // back matter: checks, gaps, sources
            } elseif ($chapter !== null && preg_match('/^### \d+\. /', $line) === 1) {
                $inPractice = true;
            } elseif ($chapter !== null && $inPractice && preg_match('/^How the stack holds it: `([^`]+)`/', $line, $m) === 1) {
                $chapters[$chapter][] = $m[1];
                $inPractice = false;
            }
        }

        return $chapters;
    }

    /** @return array<string, int> */
    private static function totals(): array
    {
        return array_count_values(array_merge(...array_values(self::chapters())));
    }

    private static function front(): string
    {
        return (string) file_get_contents(self::FRONT);
    }
}
