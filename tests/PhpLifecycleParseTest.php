<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Site\PhpLifecycle\Constraint;
use Waynestate\Creed\Site\PhpLifecycle\Parse;

require_once __DIR__ . '/../deploy/lib/PhpLifecycle/autoload.php';

/**
 * The /php page's parsers, on frozen copies of what each source sent on
 * 2026-11-19 (tests/fixtures/php-lifecycle/). The live page never reads these.
 */
final class PhpLifecycleParseTest extends TestCase
{
    private static function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/fixtures/php-lifecycle/' . $name);
        self::assertIsString($body);

        return $body;
    }

    #[Test]
    public function statesListsEveryBranchWithDays(): void
    {
        $states = Parse::states(self::fixture('states.json'));
        self::assertNotNull($states);
        self::assertSame(['state' => 'stable', 'released' => '2026-11-19', 'active_until' => '2028-12-31', 'security_until' => '2030-12-31'], $states['8.6']);
        self::assertSame('eol', $states['8.1']['state']);
        self::assertArrayHasKey('5.6', $states);
    }

    #[Test]
    public function activeGivesTheNewestPatchPerBranch(): void
    {
        $active = Parse::active(self::fixture('active.json'));
        self::assertNotNull($active);
        self::assertSame(['version' => '8.5.13', 'date' => '2026-11-19', 'security' => true], $active['8.5']);
        self::assertSame('8.6.0', $active['8.6']['version']);
        self::assertFalse($active['8.6']['security']);
    }

    #[Test]
    public function aBodyThatIsNotTheSourceIsNull(): void
    {
        $html = self::fixture('wiki-php87-404.html');
        self::assertNull(Parse::states($html));
        self::assertNull(Parse::active($html));
        self::assertNull(Parse::atom($html));
        self::assertNull(Parse::tags($html));
        self::assertNull(Parse::endoflife($html));
        self::assertNull(Parse::wiki($html));
        self::assertNull(Parse::packagist($html, 'slim/slim'));
    }

    #[Test]
    public function atomReadsReleasesAndTheNotice(): void
    {
        $entries = Parse::atom(self::fixture('feed.atom'));
        self::assertNotNull($entries);
        $releases = Parse::releasesFromAtom($entries);
        self::assertSame('8.5.13', $releases[0]['version']);
        self::assertSame('2026-11-19', $releases[0]['date']);
        self::assertTrue($releases[0]['security']);
        // "PHP 8.6.0RC7 available for testing" is not a release.
        foreach ($releases as $r) {
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $r['version']);
        }

        $notices = Parse::noticesFromAtom($entries);
        self::assertSame('2026-11-19', $notices[0]['day']);
        self::assertSame('2026-11-19T14:00:00Z', $notices[0]['start']);
        self::assertSame('2026-11-19T14:30:00Z', $notices[0]['end']);
        self::assertNull($notices[0]['branches'], 'all supported versions');
    }

    #[Test]
    public function aNoticeNamingOneBranchKeepsIt(): void
    {
        $entries = Parse::atom(self::fixture('upcoming-notice.atom'));
        self::assertNotNull($entries);
        $notices = Parse::noticesFromAtom($entries);
        self::assertCount(2, $notices);
        self::assertSame(['8.5'], $notices[1]['branches']);
        self::assertSame('2026-11-21', $notices[1]['day']);
        self::assertSame('2026-11-21T15:00:00Z', $notices[1]['start']);
    }

    #[Test]
    public function tagsSplitFinalsFromPreReleases(): void
    {
        $tags = Parse::tags(self::fixture('tags-8.json'));
        self::assertNotNull($tags);
        $byName = [];
        foreach ($tags as $t) {
            $byName[$t['version'] . ($t['pre'] ?? '')] = $t;
        }
        self::assertNull($byName['8.6.0']['pre']);
        self::assertSame('RC7', $byName['8.6.0RC7']['pre']);
        self::assertSame('alpha1', $byName['8.6.0alpha1']['pre']);
        self::assertSame('8.5', $byName['8.5.13RC1']['branch']);
    }

    #[Test]
    public function endoflifeIsReadAsACrossCheck(): void
    {
        $eol = Parse::endoflife(self::fixture('endoflife.json'));
        self::assertNotNull($eol);
        self::assertArrayNotHasKey('8.6', $eol['branches'], 'endoflife.date was a day behind on 2026-11-19');
        self::assertSame('8.5.12', $eol['branches']['8.5']['latest']);
        self::assertSame('2029-12-31', $eol['branches']['8.5']['security_until']);
    }

    #[Test]
    public function wikiFindsGaAndSkippedCandidates(): void
    {
        $wiki = Parse::wiki(self::fixture('wiki-php86.txt'));
        self::assertNotNull($wiki);
        self::assertSame('2026-11-19', $wiki['ga']);
        $rc1 = array_values(array_filter($wiki['rows'], static fn (array $r): bool => str_starts_with($r['label'], 'RC1')));
        self::assertTrue($rc1[0]['skipped']);
    }

    #[Test]
    public function packagistGivesTheNewestStableOfEachMajor(): void
    {
        $slim = Parse::packagist(self::fixture('packagist-slim.json'), 'slim/slim');
        self::assertNotNull($slim);
        self::assertSame(['4', '3'], array_column($slim, 'major'));
        self::assertStringContainsString('~8.5.0', (string) $slim[0]['php']);

        $min = Parse::packagist(self::fixture('packagist-minified.json'), 'acme/framework', 3);
        self::assertNotNull($min);
        self::assertSame(['3.1.0', '2.9.9', '1.0.0'], array_column($min, 'version'), 'betas never count');
        self::assertSame('^8.3', $min[0]['php']);
        self::assertSame('>=7.4,<8.6', $min[1]['php'], 'the newest 2.x inherits nothing it did not declare');
        self::assertSame('2026-11-01', $min[0]['released']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?bool}>
     */
    public static function constraints(): iterable
    {
        yield 'caret allows the next minor' => ['^8.3', '8.6', true];
        yield 'caret floor' => ['^8.3', '8.2', false];
        yield 'caret stops at the major' => ['^8.3', '9.0', false];
        yield 'slim lists each minor' => ['~7.4.0 || ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0', '8.6', false];
        yield 'slim lists 8.5' => ['~7.4.0 || ~8.0.0 || ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0', '8.5', true];
        yield 'cake 4 range' => ['>=7.4.0,<9', '8.6', true];
        yield 'cake 3 ceiling' => ['>=5.6.0,<8.0.0', '8.0', false];
        yield 'space is and' => ['>=8.1 <8.6', '8.5', true];
        yield 'space is and, ceiling' => ['>=8.1 <8.6', '8.6', false];
        yield 'single pipe' => ['^7.2.5|^8.0', '8.4', true];
        yield 'tilde two parts' => ['~8.2', '8.9', true];
        yield 'wildcard' => ['8.4.*', '8.4', true];
        yield 'wildcard other' => ['8.4.*', '8.5', false];
        yield 'hyphen range' => ['8.1 - 8.3', '8.3', true];
        yield 'hyphen range end' => ['8.1 - 8.3', '8.4', false];
        yield 'unreadable' => ['whatever', '8.4', null];
        yield 'empty' => ['', '8.4', null];
    }

    #[Test]
    #[DataProvider('constraints')]
    public function constraintsAnswerPerBranch(string $constraint, string $branch, ?bool $expected): void
    {
        self::assertSame($expected, Constraint::allowsBranch($constraint, $branch));
    }
}
