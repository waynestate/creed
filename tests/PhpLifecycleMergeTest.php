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
        $branch = array_values(array_filter($doc['upcoming'], static fn(array $u): bool => $u['kind'] === 'branch'));
        self::assertSame('8.6.0', $branch[0]['version']);
        self::assertSame('tagged', $branch[0]['status']);
        self::assertSame('php-src tag', $branch[0]['evidence'][0]['source']);
    }

    #[Test]
    public function aWikiTimetableSchedulesTheNextBranch(): void
    {
        $in = self::tonight();
        unset($in['states']['8.6'], $in['active']['8.6']);
        $in['tags'] = array_values(array_filter($in['tags'], static fn(array $t): bool => $t['branch'] !== '8.6'));
        $in['wiki'] = ['8.6' => Parse::wiki(self::fixture('wiki-php86.txt'))];
        $doc = self::build($in, '2026-11-01T12:00:00Z');

        self::assertSame([['kind' => 'branch', 'branches' => ['8.6'], 'version' => '8.6.0', 'status' => 'scheduled', 'day' => '2026-11-19',
            'next_step' => ['date' => '2026-11-05', 'label' => 'RC5'],
            'evidence' => [['source' => 'wiki schedule', 'label' => 'GA on the wiki schedule', 'url' => 'https://wiki.php.net/todo/php86']]]], $doc['upcoming']);
    }

    /**
     * tonight() plus php-src master and the wiki index as they were on 2026-11-20.
     *
     * @return array<string, mixed>
     */
    private static function withMaster(): array
    {
        $in = self::tonight();
        $in['master'] = Parse::phpVersionH(self::fixture('php_version.h'));
        $in['wikiPages'] = Parse::wikiIndex(self::fixture('wiki-index.txt'));

        return $in;
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, array<string, mixed>>
     */
    private static function upcomingByBranch(array $doc): array
    {
        self::assertIsArray($doc['upcoming']);
        $out = [];
        foreach ($doc['upcoming'] as $u) {
            $out[(string) $u['branches'][0]] = $u;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, array<string, mixed>>
     */
    private static function timelines(array $doc): array
    {
        self::assertIsArray($doc['timelines']);
        $out = [];
        foreach ($doc['timelines'] as $t) {
            $out[(string) $t['branch']] = $t;
        }

        return $out;
    }

    #[Test]
    public function withNoTimetableTheNextLinesSayNoTimelineAndNothingElse(): void
    {
        $doc = self::build(self::withMaster(), '2026-11-20T00:10:00Z');
        $u = self::upcomingByBranch($doc);

        self::assertSame(['8.7', '9.0'], array_keys($u));
        self::assertSame('no-timeline', $u['8.7']['status']);
        self::assertSame('development', $u['8.7']['role']);
        self::assertSame('no-timeline', $u['9.0']['status']);
        self::assertSame('major', $u['9.0']['role']);
        self::assertNull($u['8.7']['day'], 'no date is estimated');
        self::assertNull($u['9.0']['day']);
        self::assertSame([Merge::MASTER_LINK, Merge::INDEX_LINK], array_column($u['8.7']['evidence'], 'url'));
        self::assertSame([Merge::MASTER_LINK, Merge::INDEX_LINK, Merge::RFC_LINK], array_column($u['9.0']['evidence'], 'url'));
        self::assertSame('php-src master is 8.7.0-dev', $u['8.7']['evidence'][0]['label']);
    }

    #[Test]
    public function masterOnANewMajorIsTheOnlyNoTimelineLine(): void
    {
        $in = self::withMaster();
        $in['master'] = Parse::phpVersionH("#define PHP_VERSION \"9.0.0-dev\"\n");
        $u = self::upcomingByBranch(self::build($in));

        self::assertSame(['9.0'], array_keys($u));
        self::assertSame('development', $u['9.0']['role']);
    }

    #[Test]
    public function aWikiTimetableFor87ReplacesNoTimelineWithRealDates(): void
    {
        $in = self::withMaster();
        $in['wikiPages'] = Parse::wikiIndex(self::fixture('wiki-index-with-php87.txt'));
        $in['wiki'] = ['8.7' => Parse::wiki(self::fixture('wiki-php87-synthetic.txt'))];
        $doc = self::build($in, '2026-11-20T00:10:00Z');
        $u = self::upcomingByBranch($doc);

        self::assertSame('scheduled', $u['8.7']['status']);
        self::assertSame('2027-11-25', $u['8.7']['day']);
        self::assertSame(['date' => '2027-07-01', 'label' => 'Alpha 1'], $u['8.7']['next_step']);
        self::assertSame('https://wiki.php.net/todo/php87', $u['8.7']['evidence'][0]['url']);
        self::assertSame('no-timeline', $u['9.0']['status'], '9.0 still has nothing');

        $t = self::timelines($doc)['8.7'];
        self::assertSame('future', $t['state']);
        self::assertSame('published', $t['timetable']);
        self::assertSame(['2027-07-01', '2027-07-15', '2027-08-10', '2027-08-12', '2027-09-23', '2027-09-23', '2027-11-25'], array_column($t['steps'], 'date'));
        self::assertTrue($t['steps'][4]['skipped']);
        self::assertFalse($t['steps'][6]['done']);
    }

    #[Test]
    public function anAlphaTagOrANoticeAlsoReplacesNoTimeline(): void
    {
        $in = self::withMaster();
        $in['tags'][] = ['version' => '8.7.0', 'branch' => '8.7', 'pre' => 'alpha1'];
        $u = self::upcomingByBranch(self::build($in));
        self::assertSame('development', $u['8.7']['status']);

        $in = self::withMaster();
        $in['atom'] = array_merge([[
            'title' => 'Upcoming PHP 9.0 Release',
            'published' => '2026-11-19T20:00:00Z',
            'link' => 'https://www.php.net/',
            'text' => 'PHP 9.0 will be released on 26 November 2026.',
        ]], (array) $in['atom']);
        $u = self::upcomingByBranch(self::build($in));
        self::assertSame('announced', $u['9.0']['status']);
        self::assertSame('2026-11-26', $u['9.0']['day']);
    }

    #[Test]
    public function everyReleaseGetsATimelineFromWikiMilestonesAndPhpNetDates(): void
    {
        $in = self::withMaster();
        $in['wiki'] = [
            '8.6' => Parse::wiki(self::fixture('wiki-php86.txt')),
            '7.4' => Parse::wiki(self::fixture('wiki-php74.txt')),
            '5.6' => Parse::wiki(self::fixture('wiki-php56.txt')),
        ];
        $t = self::timelines(self::build($in));

        self::assertSame(['9.0', '8.7', '8.6', '8.5'], array_slice(array_keys($t), 0, 4), 'newest first, lines to come on top');
        self::assertSame('future', $t['8.7']['state']);
        self::assertSame('none', $t['8.7']['timetable']);

        $s = $t['8.6']['steps'];
        $labels = array_column($s, 'label');
        self::assertSame('Alpha 1', $labels[0]);
        self::assertSame('https://www.php.net/archive/2026.php#2026-07-02-3', $s[0]['announce']);
        $skipped = array_column(array_filter($s, static fn(array $x): bool => $x['skipped']), 'label');
        self::assertSame(['RC1', 'RC6'], $skipped, 'RC1 struck through, RC6 tagged then skipped');
        $ga = array_values(array_filter($s, static fn(array $x): bool => $x['kind'] === 'ga'));
        self::assertCount(1, $ga, 'the wiki GA and php.net release day agree, so one GA');
        self::assertSame('2026-11-19', $ga[0]['date']);
        self::assertTrue($ga[0]['done']);
        self::assertSame(['Active support ends', 'Security support ends'], array_slice($labels, -2));
        self::assertSame('2030-12-31', $s[count($s) - 1]['date']);
        self::assertFalse($s[count($s) - 1]['done']);

        self::assertSame('eol', $t['7.4']['state']);
        self::assertSame('2019-11-28', $t['7.4']['released']);
        self::assertContains('Beta 4 Beta 3 skipped', array_column($t['7.4']['steps'], 'label'));
        self::assertSame('ga', array_values(array_filter($t['5.6']['steps'], static fn(array $x): bool => $x['label'] === 'Final'))[0]['kind']);
        self::assertSame('not-read', $t['8.5']['timetable'], 'indexed, not read in this test');
    }

    #[Test]
    public function patchDatesAreOnlyWhatHappenedOrWhatPhpNetAnnounced(): void
    {
        $t = self::timelines(self::build(self::withMaster()));
        $p = $t['8.5']['patches'];

        self::assertSame('8.5.13', $p['dated'][0]['version']);
        self::assertSame('2026-11-19', $p['dated'][0]['date']);
        self::assertNull($p['next'], 'no notice, so no future patch day');
        self::assertGreaterThan(0, $p['tagged']);
        self::assertSame('8.5.1', $p['first']);

        $in = self::withMaster();
        $in['atom'] = Parse::atom(self::fixture('upcoming-notice.atom'));
        $t = self::timelines(self::build($in));
        self::assertSame('2026-11-20', $t['8.4']['patches']['next']['day']);
        self::assertSame('announced', $t['8.4']['patches']['next']['status']);
    }

    /**
     * tonight() plus Laravel's support table and CREED's own test results, as Feed hands them over.
     *
     * @return array<string, mixed>
     */
    private static function withAnnounced(): array
    {
        $in = self::tonight();
        self::assertIsArray($in['frameworks']);
        $in['frameworks']['laravel/framework']['announced'] = [
            'ok' => true,
            'source' => 'https://raw.githubusercontent.com/laravel/docs/13.x/releases.md',
            'link' => 'https://laravel.com/framework/docs/13.x/releases#support-policy',
            'fetched_at' => '2026-11-19T22:00:00Z',
            'reason' => null,
            'lines' => Parse::laravelSupport(self::fixture('laravel-releases-13x.md')),
        ];
        foreach (['cakephp/cakephp' => 'CakePHP', 'slim/slim' => 'Slim'] as $package => $name) {
            $in['frameworks'][$package]['announced'] = ['ok' => null, 'source' => null, 'link' => null, 'fetched_at' => null, 'reason' => $name . ' does not publish its supported PHP lines in a form a server can read', 'lines' => null];
        }
        $in['tests'] = Parse::frameworkTests(self::fixture('framework-php-tests-1120.json'));

        return $in;
    }
    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, array<string, mixed>>
     */
    private static function frameworks(array $doc): array
    {
        self::assertIsArray($doc['frameworks']);
        $out = [];
        foreach ($doc['frameworks'] as $f) {
            self::assertIsArray($f);
            $out[$f['name'] . ' ' . $f['major']] = $f;
        }

        return $out;
    }

    #[Test]
    public function greenNeedsLaravelsAnnouncementAndOurPass(): void
    {
        $fw = self::frameworks(self::build(self::withAnnounced()));
        $cols = array_flip(['8.2', '8.3', '8.4', '8.5', '8.6']);
        $row = static fn(string $name): array => array_intersect_key($fw[$name]['fit'], $cols);

        // The question from 2026-11-20: our Laravel 13 cell passes on 8.6, but Laravel has not announced 8.6.
        self::assertSame(['8.2' => 'no', '8.3' => 'supported', '8.4' => 'supported', '8.5' => 'supported', '8.6' => 'allowed'], $row('Laravel 13'));
        self::assertSame(['8.2' => 'supported', '8.3' => 'supported', '8.4' => 'supported', '8.5' => 'supported', '8.6' => 'allowed'], $row('Laravel 12'));
        self::assertSame('pass', $fw['Laravel 13']['our_test']['8.6']['state'], 'the title still says our test passed');
        self::assertSame('fail', $fw['Laravel 12']['our_test']['8.6']['state']);
        // CakePHP and Slim: unknown wherever Composer allows the line, even where our test passed.
        foreach (['CakePHP 5', 'CakePHP 4', 'Slim 4', 'Slim 3'] as $name) {
            self::assertSame([], array_diff(array_unique(array_values($row($name))), ['unknown', 'no']), $name);
        }
        self::assertSame('unknown', $fw['CakePHP 5']['fit']['8.6']);
        self::assertSame('pass', $fw['CakePHP 5']['our_test']['8.6']['state'], 'our test alone never makes a cell green');
        self::assertSame('no', $fw['Slim 4']['fit']['8.6']);
        self::assertSame(['8.3', '8.4', '8.5'], $fw['Laravel 13']['announced_lines']);
        self::assertSame(array_keys($fw['Laravel 13']['allows']), array_keys($fw['Laravel 13']['our_test']), 'our_test has the shape of allows');
    }

    #[Test]
    public function announcedButNotPassedSaysWhichAndNeverTurnsGreen(): void
    {
        $in = self::withAnnounced();
        self::assertIsArray($in['tests']);
        $in['tests']['cells']['laravel/framework@13']['8.4']['state'] = 'fail';
        $in['tests']['cells']['laravel/framework@13']['8.5']['state'] = 'refused';
        unset($in['tests']['cells']['laravel/framework@13']['8.3']);
        $in['tests']['cells']['laravel/framework@12']['8.2']['state'] = 'pass-deprecations';
        $fw = self::frameworks(self::build($in));

        self::assertSame('announced-untested', $fw['Laravel 13']['fit']['8.3']);
        self::assertNull($fw['Laravel 13']['our_test']['8.3'], 'missing: not run');
        self::assertSame('announced-untested', $fw['Laravel 13']['fit']['8.4']);
        self::assertSame('fail', $fw['Laravel 13']['our_test']['8.4']['state']);
        self::assertSame('announced-untested', $fw['Laravel 13']['fit']['8.5']);
        self::assertSame('refused', $fw['Laravel 13']['our_test']['8.5']['state']);
        self::assertSame('supported', $fw['Laravel 12']['fit']['8.2'], 'deprecations still pass');

        // An announcement never overrides Composer: a line require.php refuses stays "no".
        $in = self::withAnnounced();
        self::assertIsArray($in['frameworks']);
        $in['frameworks']['laravel/framework']['announced']['lines'][13] = ['8.2', '8.3', '8.4', '8.5'];
        self::assertSame('no', self::frameworks(self::build($in))['Laravel 13']['fit']['8.2']);
    }

    #[Test]
    public function noReadableAnnouncementMeansUnknownNotAllowed(): void
    {
        $fw = self::frameworks(self::build(self::tonight()));
        foreach ($fw as $name => $f) {
            self::assertNotContains('supported', $f['fit'], $name);
            self::assertNotContains('allowed', $f['fit'], $name);
            self::assertNull($f['announced'], $name);
        }

        $in = self::withAnnounced();
        self::assertIsArray($in['frameworks']);
        $in['frameworks']['laravel/framework']['announced'] = ['ok' => false, 'source' => 'https://raw.githubusercontent.com/laravel/docs/13.x/releases.md', 'link' => null, 'fetched_at' => null, 'reason' => 'HTTP 503', 'lines' => null];
        $doc = self::build($in);
        $fw = self::frameworks($doc);
        self::assertSame('unknown', $fw['Laravel 13']['fit']['8.5']);
        self::assertSame('unknown', $fw['Laravel 12']['fit']['8.6']);
        self::assertSame('no', $fw['Laravel 13']['fit']['8.2']);
        self::assertFalse($fw['Laravel 13']['announced_ok']);
        self::assertSame('HTTP 503', $fw['Laravel 13']['announced_reason']);
        self::assertIsArray($doc['notes']);
        self::assertContains("Laravel's support table could not be read (HTTP 503), so its cells say unknown, never supported.", $doc['notes']);
    }
    #[Test]
    public function cakeAndSlimRowsCarryTheLegacyLabelAndTheSameFit(): void
    {
        $in = self::tonight();
        $plain = self::build($in);
        foreach (['cakephp/cakephp', 'slim/slim'] as $package) {
            $in['frameworks'][$package]['lifecycle'] = 'legacy';
            $in['frameworks'][$package]['lifecycle_note'] = 'New apps use raw PHP or Laravel (D-124).';
        }
        $doc = self::build($in);
        self::assertIsArray($doc['frameworks']);
        self::assertIsArray($plain['frameworks']);
        foreach ($doc['frameworks'] as $i => $f) {
            self::assertSame($f['name'] === 'Laravel' ? null : 'legacy', $f['lifecycle']);
            self::assertSame($plain['frameworks'][$i]['allows'], $f['allows'], 'the label changes nothing in the fit');
        }
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
        $in['tags'] = array_values(array_filter($in['tags'], static fn(array $t): bool => $t['version'] !== '8.5.13'));
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
        $dev = array_values(array_filter($doc['upcoming'], static fn(array $u): bool => $u['branches'] === ['8.7']));
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
