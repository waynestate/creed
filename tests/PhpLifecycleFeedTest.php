<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Site\PhpLifecycle\Feed;
use Waynestate\Creed\Site\PhpLifecycle\Http;

require_once __DIR__ . '/../deploy/lib/PhpLifecycle/autoload.php';

/**
 * The feed's cache: fresh copies are served as they are, a refresh is at
 * most every ten minutes, and a source that fails keeps its last good answer
 * marked stale. The network is a fake that answers from the fixtures.
 */
final class PhpLifecycleFeedTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/creed-php-feed-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * A fake network: every URL answers from the fixtures unless it is listed as down.
     *
     * @param list<string> $down source ids that fail
     * @param array<string, string> $files id => fixture file, replacing or adding to the usual ones
     */
    private static function net(array $down = [], array $files = []): PhpLifecycleFakeHttp
    {
        return new PhpLifecycleFakeHttp($down, $files);
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, array<string, mixed>>
     */
    private static function sources(array $doc): array
    {
        self::assertIsArray($doc['sources']);
        $out = [];
        foreach ($doc['sources'] as $s) {
            self::assertIsArray($s);
            $out[(string) $s['id']] = $s;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function wikiAsked(PhpLifecycleFakeHttp $net): array
    {
        return array_values(array_filter($net->asked, static fn(string $id): bool => str_starts_with($id, 'wiki-') && $id !== 'wiki-index'));
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
            self::assertIsArray($t);
            $out[(string) $t['branch']] = $t;
        }

        return $out;
    }

    #[Test]
    public function aFirstRequestFetchesEverySourceOnceAndAsksGithubLittle(): void
    {
        $net = self::net();
        $doc = (new Feed($this->dir, $net))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));

        self::assertSame('server', $doc['mode']);
        self::assertFalse($doc['stale']);
        self::assertSame('8.6', $doc['newest']);
        $wiki = self::wikiAsked($net);
        self::assertSame(2 + count($wiki), $net->calls, 'one round for the fixed sources, one for tags, then one wiki page at a time');
        $github = array_filter($net->asked, static fn(string $id): bool => str_starts_with($id, 'tags-'));
        self::assertSame(['tags-8', 'tags-9'], array_values($github), 'one call per live major and the next');
        self::assertContains('master', $net->asked);
        self::assertContains('wiki-index', $net->asked);
        $s = self::sources($doc);
        self::assertTrue($s['states']['ok']);
        self::assertTrue($s['master']['ok']);
        self::assertTrue($s['wiki-index']['ok']);
        self::assertTrue($s['wiki-8.5']['ok'], 'no page is an answer, not an outage');
        self::assertFalse($s['tags-9']['ok']);
        self::assertTrue($s['endoflife']['browser']);
        self::assertFalse($s['states']['browser']);
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

    private const TESTS = __DIR__ . '/fixtures/php-lifecycle/framework-php-tests-1120.json';

    /**
     * One refresh with no cache, so every scenario reads the sources itself.
     *
     * @param list<string> $down
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function fresh(array $down = [], array $files = [], ?string $tests = self::TESTS): array
    {
        return (new Feed($this->dir, self::net($down, $files), $tests))->refresh(null, new DateTimeImmutable('2026-11-19T22:00:00Z'))['doc'];
    }

    #[Test]
    public function frameworkFitJoinsLaravelsSupportTableAndOurOwnTests(): void
    {
        $net = self::net();
        $doc = (new Feed($this->dir, $net, self::TESTS))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));

        // Laravel's table comes from the newest major's docs branch; no CI workflow is read any more.
        self::assertSame('https://raw.githubusercontent.com/laravel/docs/13.x/releases.md', $net->urls['announced-laravel/framework']);
        self::assertSame([], array_values(array_filter($net->asked, static fn(string $id): bool => str_starts_with($id, 'ci-'))));
        $s = self::sources($doc);
        self::assertTrue($s['announced-laravel/framework']['ok']);
        self::assertSame('announced', $s['announced-laravel/framework']['group']);
        self::assertSame('Laravel support policy (13.x docs)', $s['announced-laravel/framework']['label']);
        self::assertFalse($s['announced-laravel/framework']['browser']);

        $fw = self::frameworks($doc);
        $l13 = $fw['Laravel 13'];
        self::assertIsArray($l13['fit']);
        self::assertSame('allowed', $l13['fit']['8.6'], 'Composer allows 8.6 and our test passed, but Laravel has not announced it');
        self::assertSame('supported', $l13['fit']['8.5'], 'announced and our cell passed');
        self::assertSame('supported', $l13['fit']['8.3']);
        self::assertSame('no', $l13['fit']['8.2']);
        self::assertSame(['8.3', '8.4', '8.5'], $l13['announced_lines']);
        self::assertTrue($l13['announced_ok']);
        self::assertSame('https://raw.githubusercontent.com/laravel/docs/13.x/releases.md', $l13['announced_source']);
        self::assertSame('https://laravel.com/framework/docs/13.x/releases#support-policy', $l13['announced_link']);
        self::assertSame('2026-11-19T22:00:00Z', $l13['announced_fetched_at']);
        self::assertArrayNotHasKey('tested', $l13);
        self::assertIsArray($l13['our_test']);
        self::assertIsArray($l13['our_test']['8.5']);
        self::assertSame('pass', $l13['our_test']['8.5']['state']);
        self::assertSame('8.5.13', $l13['our_test']['8.5']['php_version']);

        $l12 = $fw['Laravel 12'];
        self::assertIsArray($l12['fit']);
        self::assertSame('allowed', $l12['fit']['8.6'], 'not announced; our failed test does not make it red');
        self::assertSame('supported', $l12['fit']['8.5']);
        self::assertIsArray($l12['our_test']);
        self::assertIsArray($l12['our_test']['8.6']);
        self::assertSame('fail', $l12['our_test']['8.6']['state']);

        // CakePHP and Slim publish nothing a server can read: unknown, whatever our test says.
        self::assertIsArray($fw['CakePHP 5']['fit']);
        self::assertSame('unknown', $fw['CakePHP 5']['fit']['8.6']);
        self::assertSame('unknown', $fw['CakePHP 5']['fit']['8.5']);
        self::assertNull($fw['CakePHP 5']['announced_ok']);
        self::assertStringContainsString('does not publish', (string) $fw['CakePHP 5']['announced_reason']);
        self::assertIsArray($fw['Slim 4']['fit']);
        self::assertSame('unknown', $fw['Slim 4']['fit']['8.5']);
        self::assertIsArray($doc['framework_tests']);
        self::assertTrue($doc['framework_tests']['ok']);
    }

    #[Test]
    public function anUnreadSupportTableDropsLaravelToUnknownNeverRed(): void
    {
        $doc = $this->fresh(['announced-laravel/framework']);
        $fw = self::frameworks($doc);
        foreach (['8.3', '8.4', '8.5', '8.6'] as $line) {
            self::assertIsArray($fw['Laravel 13']['fit']);
            self::assertSame('unknown', $fw['Laravel 13']['fit'][$line], 'PHP ' . $line);
        }
        self::assertSame('no', $fw['Laravel 13']['fit']['8.2'], 'what Composer refuses stays refused');
        self::assertFalse($fw['Laravel 13']['announced_ok']);
        self::assertSame('timed out', $fw['Laravel 13']['announced_reason']);
        self::assertNull($fw['Laravel 13']['announced_fetched_at']);
        self::assertSame('https://raw.githubusercontent.com/laravel/docs/13.x/releases.md', $fw['Laravel 13']['announced_source']);
        self::assertIsArray($doc['notes']);
        self::assertNotEmpty(array_filter($doc['notes'], static fn(mixed $n): bool => is_string($n) && str_contains($n, "Laravel's support table could not be read")));
    }

    #[Test]
    public function aGoodTableReadEarlierDoesNotKeepCellsGreenAfterAFailedRead(): void
    {
        $now = new DateTimeImmutable('2026-11-19T22:00:00Z');
        $first = (new Feed($this->dir, self::net(), self::TESTS))->refresh(null, $now);
        $fw = self::frameworks($first['doc']);
        self::assertIsArray($fw['Laravel 13']['fit']);
        self::assertSame('supported', $fw['Laravel 13']['fit']['8.5']);

        $again = (new Feed($this->dir, self::net(['announced-laravel/framework']), self::TESTS))->refresh($first, $now->modify('+11 minutes'));
        $fw = self::frameworks($again['doc']);
        self::assertIsArray($fw['Laravel 13']['fit']);
        self::assertSame('unknown', $fw['Laravel 13']['fit']['8.5'], 'no stale green: the last good table is not reused');
    }

    #[Test]
    public function aTableInAnotherLayoutOrWithoutTheMajorIsUnknown(): void
    {
        $fw = self::frameworks($this->fresh([], ['announced-laravel/framework' => 'laravel-releases-list-layout.md']));
        self::assertIsArray($fw['Laravel 13']['fit']);
        self::assertSame('unknown', $fw['Laravel 13']['fit']['8.5']);
        self::assertSame('the Support Policy table did not parse', $fw['Laravel 13']['announced_reason']);

        $fw = self::frameworks($this->fresh([], ['announced-laravel/framework' => 'laravel-releases-no-13.md']));
        self::assertIsArray($fw['Laravel 13']['fit']);
        self::assertSame('unknown', $fw['Laravel 13']['fit']['8.5']);
        self::assertFalse($fw['Laravel 13']['announced_ok']);
        self::assertSame('the Support Policy table has no row for Laravel 13', $fw['Laravel 13']['announced_reason']);
        self::assertIsArray($fw['Laravel 12']['fit']);
        self::assertSame('supported', $fw['Laravel 12']['fit']['8.5'], 'the rows that are there still count');
    }

    #[Test]
    public function withoutOurTestDataNoCellIsSupported(): void
    {
        $doc = $this->fresh([], [], $this->dir . '/no-such-file.json');
        $fw = self::frameworks($doc);
        self::assertIsArray($fw['Laravel 13']['fit']);
        self::assertSame('announced-untested', $fw['Laravel 13']['fit']['8.5']);
        self::assertSame('allowed', $fw['Laravel 13']['fit']['8.6']);
        self::assertIsArray($fw['Laravel 13']['our_test']);
        self::assertNull($fw['Laravel 13']['our_test']['8.5']);
        self::assertIsArray($doc['framework_tests']);
        self::assertFalse($doc['framework_tests']['ok']);

        $doc = $this->fresh([], [], __DIR__ . '/fixtures/php-lifecycle/states.json');
        $fw = self::frameworks($doc);
        self::assertIsArray($fw['Laravel 13']['fit']);
        self::assertSame('announced-untested', $fw['Laravel 13']['fit']['8.5'], 'a file that is not the matrix counts as missing');
    }
    #[Test]
    public function theWikiIsAskedForIndexedPagesOnlyOneASecond(): void
    {
        $net = self::net();
        (new Feed($this->dir, $net))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));

        // Supported releases every time, then the newest closed ones (three per refresh).
        self::assertSame(['wiki-8.6', 'wiki-8.5', 'wiki-8.4', 'wiki-8.3', 'wiki-8.2', 'wiki-8.1', 'wiki-8.0', 'wiki-7.4'], self::wikiAsked($net));
        self::assertNotContains('wiki-8.7', $net->asked, 'no page name is guessed');
        self::assertNotContains('wiki-6.0', $net->asked, 'a struck-through index entry is not a release');
        self::assertCount(count(self::wikiAsked($net)), $net->pauses);
        foreach ($net->pauses as $p) {
            self::assertGreaterThanOrEqual(1.0, $p);
        }
    }

    #[Test]
    public function aClosedReleasesTimetableIsReadOnceAndKept(): void
    {
        (new Feed($this->dir, self::net()))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));
        $next = self::net();
        $doc = (new Feed($this->dir, $next))->get(new DateTimeImmutable('2026-11-19T22:10:00Z'));

        self::assertSame(['wiki-8.6', 'wiki-8.5', 'wiki-8.4', 'wiki-8.3', 'wiki-8.2', 'wiki-7.3', 'wiki-7.2', 'wiki-7.1'], self::wikiAsked($next));
        $s = self::sources($doc);
        self::assertArrayNotHasKey('wiki-7.4', $s, 'closed pages share one source line');
        self::assertTrue($s['wiki-archive']['ok']);
        self::assertFalse($s['wiki-archive']['stale'], 'a page that never changes does not go stale');
        self::assertStringContainsString('6 of', (string) $s['wiki-archive']['label']);
        $t = self::timelines($doc);
        self::assertSame('published', $t['7.4']['timetable'], 'the page read ten minutes ago still counts');
        self::assertSame('not-read', $t['5.6']['timetable']);
    }

    #[Test]
    public function aWikiThatDoesNotAnswerStopsAfterOnePage(): void
    {
        $net = self::net(['wiki-8.6']);
        $doc = (new Feed($this->dir, $net))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));

        self::assertSame(['wiki-8.6'], self::wikiAsked($net));
        self::assertSame('not fetched: the host did not answer', self::sources($doc)['wiki-8.5']['error']);
    }

    #[Test]
    public function withNoTimetablesTheNextLinesSayNoTimelineYet(): void
    {
        $doc = (new Feed($this->dir, self::net()))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));
        self::assertIsArray($doc['upcoming']);
        $rows = array_values(array_filter($doc['upcoming'], static fn(array $u): bool => $u['status'] === 'no-timeline'));

        self::assertSame([['8.7'], ['9.0']], array_column($rows, 'branches'));
        self::assertSame(['development', 'major'], array_column($rows, 'role'));
        self::assertSame('2026-11-19T22:00:00Z', $rows[0]['checked_at']);
        self::assertNull($rows[0]['day'], 'no estimate');
    }

    #[Test]
    public function aNewlyIndexedTimetableIsPickedUpAndReplacesNoTimeline(): void
    {
        $net = self::net([], ['wiki-index' => 'wiki-index-with-php87.txt', 'wiki-8.7' => 'wiki-php87-synthetic.txt']);
        $doc = (new Feed($this->dir, $net))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));

        self::assertSame('wiki-8.7', self::wikiAsked($net)[0], 'a future release is read every refresh');
        self::assertIsArray($doc['upcoming']);
        $by = [];
        foreach ($doc['upcoming'] as $u) {
            $by[$u['branches'][0]] = $u;
        }
        self::assertSame('scheduled', $by['8.7']['status']);
        self::assertSame('2027-11-25', $by['8.7']['day']);
        self::assertSame(['date' => '2027-07-01', 'label' => 'Alpha 1'], $by['8.7']['next_step']);
        self::assertSame('no-timeline', $by['9.0']['status']);
        self::assertTrue(self::sources($doc)['wiki-8.7']['ok']);
        self::assertSame('future', self::timelines($doc)['8.7']['state']);
    }

    #[Test]
    public function aFreshCacheIsServedWithoutFetching(): void
    {
        $net = self::net();
        $feed = new Feed($this->dir, $net);
        $feed->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));
        $calls = $net->calls;
        $doc = $feed->get(new DateTimeImmutable('2026-11-19T22:09:00Z'));

        self::assertSame($calls, $net->calls);
        self::assertSame(540, $doc['age_seconds']);
        $feed->get(new DateTimeImmutable('2026-11-19T22:10:00Z'));
        self::assertGreaterThan($calls, $net->calls, 'ten minutes on, it refreshes');
    }

    #[Test]
    public function aFailedSourceKeepsItsLastGoodAnswerMarkedStale(): void
    {
        (new Feed($this->dir, self::net()))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));
        $doc = (new Feed($this->dir, self::net(['states', 'active'])))->get(new DateTimeImmutable('2026-11-19T22:45:00Z'));
        $s = self::sources($doc);

        self::assertFalse($s['states']['ok']);
        self::assertTrue($s['states']['stale']);
        self::assertSame('2026-11-19T22:00:00Z', $s['states']['ok_at']);
        self::assertSame('timed out', $s['states']['error']);
        self::assertFalse($s['atom']['stale']);
        self::assertSame('8.6', $doc['newest'], 'the last good branch list still stands');
    }

    #[Test]
    public function aSourceNeverReadIsDownAndInventsNothing(): void
    {
        $doc = (new Feed($this->dir, self::net(['states', 'active', 'atom', 'endoflife'])))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));
        $s = self::sources($doc);

        self::assertFalse($s['states']['ok']);
        self::assertNull($s['states']['ok_at']);
        self::assertSame([], $doc['branches']);
        self::assertArrayNotHasKey('tags-8', $s, 'with no branch list there is no major to ask about');
    }
}

/**
 * The fake network behind PhpLifecycleFeedTest, with counters the tests read.
 */
final class PhpLifecycleFakeHttp extends Http
{
    public int $calls = 0;

    /** @var list<string> */
    public array $asked = [];

    /** @var list<float> */
    public array $pauses = [];

    /** @var array<string, string> id => the URL asked for */
    public array $urls = [];

    /**
     * @param list<string> $down
     * @param array<string, string> $files id => fixture, on top of the usual ones
     */
    public function __construct(public array $down, public array $files = []) {}

    protected function pause(float $seconds): void
    {
        $this->pauses[] = $seconds;
    }

    public function fetchAll(array $urls): array
    {
        $this->calls++;
        $map = [
            'states' => 'states.json',
            'active' => 'active.json',
            'atom' => 'feed.atom',
            'endoflife' => 'endoflife.json',
            'packagist-laravel/framework' => 'packagist-laravel.json',
            'packagist-cakephp/cakephp' => 'packagist-cakephp.json',
            'packagist-slim/slim' => 'packagist-slim.json',
            'tags-8' => 'tags-8.json',
            'master' => 'php_version.h',
            'wiki-index' => 'wiki-index.txt',
            'wiki-8.6' => 'wiki-php86.txt',
            'wiki-7.4' => 'wiki-php74.txt',
            'wiki-5.6' => 'wiki-php56.txt',
            'wiki-5.4' => 'wiki-php54.txt',
            'announced-laravel/framework' => 'laravel-releases-13x.md',

        ];
        $map = $this->files + $map;
        $out = [];
        foreach ($urls as $id => $url) {
            $this->asked[] = $id;
            $this->urls[$id] = $url;
            $file = $map[$id] ?? null;
            if (in_array($id, $this->down, true)) {
                $out[$id] = ['status' => 0, 'body' => null, 'error' => 'timed out'];
            } elseif ($file === null) {
                $out[$id] = ['status' => 404, 'body' => '<!doctype html><title>Not found</title>', 'error' => 'HTTP 404'];
            } else {
                $out[$id] = ['status' => 200, 'body' => (string) file_get_contents(__DIR__ . '/fixtures/php-lifecycle/' . $file), 'error' => null];
            }
        }

        return $out;
    }
}
