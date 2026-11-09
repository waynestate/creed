<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the public pages from counting Wayne State apps. Proof runs
 * against real applications live on the private proof site; anything public
 * speaks in percentages and trends, never "37 of 41 apps", and never names an
 * app or a team. Names cannot be caught by a pattern, so review still owns
 * those; this test catches the counts.
 *
 * Creed Bratton has never given anyone a straight number about anything. On
 * this one point, the public pages follow his lead.
 */
final class PublicProofCopyTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** A digit count of apps, teams or departments: "82 apps", "37 of 41 Wayne State applications". */
    private const COUNT = '~\b\d[\d,]*\s+(?:of\s+(?:the\s+)?\d[\d,]*\s+)?(?:(?:Wayne\s+State|WSU|scanned|Citadel)\s+)?(?:apps|applications|teams|departments)\b~i';

    #[Test]
    public function noPublicPageCountsApps(): void
    {
        $found = [];
        foreach (self::publicFiles() as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $no => $line) {
                if (preg_match(self::COUNT, $line, $m) === 1) {
                    $found[] = substr($file, strlen(self::ROOT) + 1) . ':' . ($no + 1) . ': "' . $m[0] . '"';
                }
            }
        }

        self::assertSame([], $found, "Public copy states app counts; use percentages and trends:\n" . implode("\n", $found));
    }

    #[Test]
    public function thePatternCatchesTheOldProofLine(): void
    {
        self::assertSame(1, preg_match(self::COUNT, '82 Wayne State applications read against CREED'));
        self::assertSame(1, preg_match(self::COUNT, 'already 37 of 41 apps meet the floor'));
        self::assertSame(0, preg_match(self::COUNT, 'about nine in ten scanned apps meet the floor'));
    }

    /** @return list<string> the hand-written public sources; generated pages are built from these. */
    private static function publicFiles(): array
    {
        $files = [
            self::ROOT . '/README.md',
            self::ROOT . '/BEST_PRACTICES.md',
            self::ROOT . '/site/index.html',
            self::ROOT . '/site/llms.txt',
        ];
        foreach (['/handbook/*.md', '/docs/*.md', '/docs/*/*.md'] as $glob) {
            $files = array_merge($files, glob(self::ROOT . $glob) ?: []);
        }
        self::assertNotEmpty($files);

        return array_values(array_filter($files, 'is_file'));
    }
}
