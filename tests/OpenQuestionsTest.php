<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps OPEN_QUESTIONS.md and the front page's errata slip level. Every row
 * the slip marks Open is named in the file ("front page E-n"), and every row
 * the file names is still Open on the slip, so a question closed in one place
 * cannot stay open in the other.
 *
 * Creed Bratton keeps his open questions in a shoebox under the desk. These
 * live in two public places, and this test reads both.
 */
final class OpenQuestionsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    #[Test]
    public function everyOpenErratumIsAnOpenQuestionAndBack(): void
    {
        $open = self::openErrata(self::read('site/index.html'));
        $named = self::namedErrata(self::read('OPEN_QUESTIONS.md'));

        self::assertNotSame([], $open, 'The front page shows no open errata row; the parser no longer matches the markup.');
        self::assertSame([], array_values(array_diff($open, $named)), 'Open on the front page, missing from OPEN_QUESTIONS.md.');
        self::assertSame([], array_values(array_diff($named, $open)), 'Named in OPEN_QUESTIONS.md, not an open row on the front page.');
    }

    #[Test]
    public function theParsersReadTheMarkupTheyMeanTo(): void
    {
        $html = '<tr><th scope="row">E-4</th><td>§9.3</td><td>x</td><td><span class="settled">Settled</span></td></tr>'
            . '<tr><th scope="row">E-6</th><td>Ch. 10</td><td>y</td><td><span class="open">Open</span></td></tr>';
        self::assertSame(['E-6'], self::openErrata($html));

        $markdown = "1. **One** (front page E-9). 2. **Two** (practice 45, front page E-12).\n3. See E-4 for history.";
        self::assertSame(['E-9', 'E-12'], self::namedErrata($markdown));
    }

    /** @return list<string> the row numbers the errata table marks Open */
    private static function openErrata(string $html): array
    {
        // A row is one <tr>; the body never crosses into the next row.
        preg_match_all('~<tr><th scope="row">(E-\d+)</th>(?:(?!</tr>).)*?<span class="open">Open</span></td></tr>~', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> the rows the file names as "front page E-n" (a range such as "E-6 onward" is not a row) */
    private static function namedErrata(string $markdown): array
    {
        preg_match_all('~front\s+page\s+(E-\d++)(?!\s+onward)~', $markdown, $m);

        return array_values(array_unique($m[1]));
    }

    private static function read(string $path): string
    {
        $text = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($text, 'Cannot read ' . $path);

        return $text;
    }
}
