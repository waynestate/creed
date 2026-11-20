<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Site\PhpLifecycle\Merge;
use Waynestate\Creed\Site\PhpLifecycle\Parse;

require_once __DIR__ . '/../deploy/lib/PhpLifecycle/autoload.php';

/**
 * The /php page's merge rules on frozen feeds from the night 8.6.0 shipped.
 * Every edge case in the brief has a test: a new branch, tagged but not
 * announced, announced but not tagged, a source down, sources that disagree,
 * and release candidates that must never count as releases.
 */
final class PhpLifecycleMergeTest extends TestCase
{
    private const NOW = '2026-11-19T22:00:00Z';

    private static function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/fixtures/php-lifecycle/' . $name);
        self::assertIsString($body);

        return $body;
    }

    /**
     * The inputs exactly as the sources sent them that night.
     *
     * @return array<string, mixed>
     */
    private static function tonight(): array
    {
        return [
            'states' => Parse::states(self::fixture('states.json')),
            'active' => Parse::active(self::fixture('active.json')),
            'atom' => Parse::atom(self::fixture('feed.atom')),
            'tags' => Parse::tags(self::fixture('tags-8.json')),
            'endoflife' => Parse::endoflife(self::fixture('endoflife.json')),
            'wiki' => ['8.7' => null, '9.0' => null],
            'frameworks' => [
                'laravel/framework' => ['name' => 'Laravel', 'url' => 'https://packagist.org/packages/laravel/framework', 'majors' => Parse::packagist(self::fixture('packagist-laravel.json'), 'laravel/framework')],
                'cakephp/cakephp' => ['name' => 'CakePHP', 'url' => 'https://packagist.org/packages/cakephp/cakephp', 'majors' => Parse::packagist(self::fixture('packagist-cakephp.json'), 'cakephp/cakephp')],
                'slim/slim' => ['name' => 'Slim', 'url' => 'https://packagist.org/packages/slim/slim', 'majors' => Parse::packagist(self::fixture('packagist-slim.json'), 'slim/slim')],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    private static function build(array $in, string $now = self::NOW): array
    {
        /** @phpstan-ignore argument.type (fixture inputs come straight from Parse) */
        return Merge::build($in, new DateTimeImmutable($now));
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, array<string, mixed>>
     */
    private static function branches(array $doc): array
    {
        self::assertIsArray($doc['branches']);
        $out = [];
        foreach ($doc['branches'] as $b) {
            self::assertIsArray($b);
            $out[(string) $b['branch']] = $b;
        }

        return $out;
    }

    #[Test]
    public function tonightShowsTheNewBranchAndEveryPatch(): void
    {
        $doc = self::build(self::tonight());
        $b = self::branches($doc);

        self::assertSame('8.6', $doc['newest']);
        self::assertTrue($b['8.6']['is_new']);
        self::assertSame('active', $b['8.6']['state']);
        self::assertSame('8.6.0', $b['8.6']['latest']['version']);
        self::assertSame('8.5.13', $b['8.5']['latest']['version']);
        self::assertTrue($b['8.5']['latest']['security']);
        self::assertSame('php.net releases', $b['8.5']['latest']['source']);
        self::assertSame('security', $b['8.3']['state']);
        self::assertSame(407, $b['8.3']['days_left']);
        self::assertFalse($b['8.5']['is_new']);

        // Oldest relevant first; recent EOL branches stay, ancient ones go.
        self::assertSame('eol', $b['8.1']['state']);
        self::assertArrayHasKey('8.0', $b);
        self::assertArrayNotHasKey('7.4', $b);
        self::assertSame(array_keys($b), ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6']);

        // The 2026-11-19 notice was met by the 2026-11-19 releases, so nothing is pending.
        self::assertSame([], $doc['upcoming']);
        self::assertNull($b['8.5']['next']);

        self::assertContains('endoflife.date does not list 8.6 yet; php.net does.', $doc['notes']);
    }

    #[Test]
    public function frameworkFitComesFromRequirePhp(): void
    {
        $doc = self::build(self::tonight());
        self::assertIsArray($doc['frameworks']);
        $fit = [];
        foreach ($doc['frameworks'] as $f) {
            $fit[$f['name'] . ' ' . $f['major']] = $f['allows'];
        }
        self::assertTrue($fit['Laravel 13']['8.6']);
        self::assertFalse($fit['Laravel 13']['8.2']);
        self::assertFalse($fit['Slim 4']['8.6'], 'Slim 4.15 lists each minor and stops at 8.5');
        self::assertTrue($fit['Slim 4']['8.5']);
        self::assertTrue($fit['CakePHP 4']['8.6']);
        self::assertTrue($fit['CakePHP 5']['8.6']);
        self::assertFalse($fit['CakePHP 5']['8.1']);
    }

    #[Test]
    public function aBranchTaggedBeforePhpNetListsItIsUpcoming(): void
    {
        $in = self::tonight();
        unset($in['states']['8.6'], $in['active']['8.6']);
        $doc = self::build($in, '2026-11-19T09:00:00Z');

        self::assertArrayNotHasKey('8.6', self::branches($doc));
        self::assertSame('8.5', $doc['newest']);
        $branch = array_values(array_filter($doc['upcoming'], static fn (array $u): bool => $u['kind'] === 'branch'));
        self::assertSame('8.6.0', $branch[0]['version']);
        self::assertSame('tagged', $branch[0]['status']);
        self::assertSame('php-src tag', $branch[0]['evidence'][0]['source']);
    }

    #[Test]
    public function aWikiTimetableSchedulesTheNextBranch(): void
    {
        $in = self::tonight();
        unset($in['states']['8.6'], $in['active']['8.6']);
        $in['tags'] = array_values(array_filter($in['tags'], static fn (array $t): bool => $t['branch'] !== '8.6'));
        $in['wiki'] = ['8.6' => Parse::wiki(self::fixture('wiki-php86.txt'))];
        $doc = self::build($in, '2026-11-01T12:00:00Z');

        self::assertSame([['kind' => 'branch', 'branches' => ['8.6'], 'version' => '8.6.0', 'status' => 'scheduled', 'day' => '2026-11-19',
            'evidence' => [['source' => 'wiki schedule', 'label' => 'GA on the wiki schedule', 'url' => 'https://wiki.php.net/todo/php86']]]], $doc['upcoming']);
    }

    #[Test]
    public function aPatchTaggedButNotAnnouncedIsUpcomingNotReleased(): void
    {
        $in = self::tonight();
        $in['tags'][] = ['version' => '8.5.14', 'branch' => '8.5', 'pre' => null];
        $doc = self::build($in);
        $b = self::branches($doc);

        self::assertSame('8.5.13', $b['8.5']['latest']['version']);
        self::assertSame('8.5.14', $b['8.5']['next']['version']);
        self::assertSame('tagged', $b['8.5']['next']['status']);
        self::assertSame('Tagged in php-src, not announced on php.net', $b['8.5']['next']['evidence'][0]['label']);
    }

    #[Test]
    public function aPatchAnnouncedButNotTaggedIsStillTheRelease(): void
    {
        $in = self::tonight();
        $in['tags'] = array_values(array_filter($in['tags'], static fn (array $t): bool => $t['version'] !== '8.5.13'));
        $doc = self::build($in);
        $b = self::branches($doc);

        self::assertSame('8.5.13', $b['8.5']['latest']['version']);
        self::assertNull($b['8.5']['next']);
    }

    #[Test]
    public function releaseCandidatesAreNeverReleases(): void
    {
        $in = self::tonight();
        $in['tags'][] = ['version' => '8.5.14', 'branch' => '8.5', 'pre' => 'RC1'];
        $in['tags'][] = ['version' => '8.7.0', 'branch' => '8.7', 'pre' => 'alpha1'];
        $doc = self::build($in);
        $b = self::branches($doc);

        self::assertSame('8.5.13', $b['8.5']['latest']['version']);
        self::assertSame('candidate', $b['8.5']['next']['status']);
        self::assertSame('RC1', $b['8.5']['next']['pre']);
        self::assertArrayNotHasKey('8.7', $b);
        $dev = array_values(array_filter($doc['upcoming'], static fn (array $u): bool => $u['branches'] === ['8.7']));
        self::assertSame('development', $dev[0]['status']);
    }

    #[Test]
    public function aNoticeForTomorrowIsAnnouncedOnEverySupportedBranch(): void
    {
        $in = self::tonight();
        $in['atom'] = Parse::atom(self::fixture('upcoming-notice.atom'));
        $doc = self::build($in);
        $b = self::branches($doc);

        self::assertSame('security', $doc['upcoming'][0]['kind']);
        self::assertSame('announced', $doc['upcoming'][0]['status']);
        self::assertSame('2026-11-20T14:00:00Z', $doc['upcoming'][0]['start']);
        self::assertSame(['8.2', '8.3', '8.4', '8.5', '8.6'], $doc['upcoming'][0]['branches']);
        self::assertSame('announced', $b['8.4']['next']['status']);
        self::assertSame('2026-11-20', $b['8.4']['next']['day']);
        self::assertNull($b['8.1']['next'], 'an EOL branch gets no security release');
        // The one-branch notice lands only on that branch, after the first.
        self::assertSame(['8.5'], $doc['upcoming'][1]['branches']);
        self::assertSame('2026-11-21', $b['8.5']['next']['day']);
    }

    #[Test]
    public function aNoticePastItsWindowIsDueThenGone(): void
    {
        $in = self::tonight();
        $in['atom'] = Parse::atom(self::fixture('upcoming-notice.atom'));
        self::assertSame('due', self::build($in, '2026-11-20T16:00:00Z')['upcoming'][0]['status']);
        self::assertSame([], self::build($in, '2026-11-23T16:00:00Z')['upcoming']);
    }

    #[Test]
    public function sourcesThatDisagreeShowBothAndPhpNetWins(): void
    {
        $in = self::tonight();
        $in['endoflife']['branches']['8.4']['security_until'] = '2029-06-30';
        $b = self::branches(self::build($in));

        self::assertSame('2028-12-31', $b['8.4']['security_until']);
        self::assertSame([['field' => 'security_until', 'label' => 'security support ends', 'php_net' => '2028-12-31', 'endoflife' => '2029-06-30']], $b['8.4']['disagreements']);
        self::assertSame([], $b['8.5']['disagreements']);
    }

    #[Test]
    public function phpNetDownFallsBackToEndoflifeAndSaysSo(): void
    {
        $in = self::tonight();
        $in['states'] = null;
        $in['active'] = null;
        $in['atom'] = null;
        $doc = self::build($in);
        $b = self::branches($doc);

        self::assertArrayNotHasKey('8.6', $b, 'nothing endoflife.date did not say');
        self::assertSame('endoflife.date', $b['8.5']['dates_from']);
        self::assertSame('8.5.12', $b['8.5']['latest']['version']);
        $byBranch = [];
        foreach ($doc['upcoming'] as $u) {
            $byBranch[$u['branches'][0]] = $u;
        }
        self::assertSame('8.6.0', $byBranch['8.6']['version'], 'php-src still shows the new branch');
        self::assertSame('tagged', $byBranch['8.6']['status']);
        self::assertSame('8.5.13', $byBranch['8.5']['version'], 'endoflife.date had 8.5.12; the tag is newer but not a release');
        self::assertSame('tagged', $byBranch['8.5']['status']);
        self::assertStringContainsString('endoflife.date', $doc['notes'][0]);
    }

    #[Test]
    public function everySourceDownInventsNothing(): void
    {
        $doc = self::build([]);
        self::assertSame([], $doc['branches']);
        self::assertSame([], $doc['upcoming']);
        self::assertSame([], $doc['frameworks']);
        self::assertNull($doc['newest']);
    }
}
