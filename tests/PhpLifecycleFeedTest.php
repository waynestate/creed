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
     */
    private static function net(array $down = []): Http
    {
        return new class ($down) extends Http {
            public int $calls = 0;

            /** @var list<string> */
            public array $asked = [];

            /**
             * @param list<string> $down
             */
            public function __construct(public array $down) {}

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
                    'wiki-8.7' => 'wiki-php87-404.html',
                    'wiki-9.0' => 'wiki-php87-404.html',
                ];
                $out = [];
                foreach ($urls as $id => $url) {
                    $this->asked[] = $id;
                    $file = $map[$id] ?? null;
                    if (in_array($id, $this->down, true)) {
                        $out[$id] = ['status' => 0, 'body' => null, 'error' => 'timed out'];
                    } elseif ($file === null || str_starts_with($id, 'wiki-')) {
                        $out[$id] = ['status' => 404, 'body' => '<!doctype html><title>Not found</title>', 'error' => 'HTTP 404'];
                    } else {
                        $out[$id] = ['status' => 200, 'body' => (string) file_get_contents(__DIR__ . '/fixtures/php-lifecycle/' . $file), 'error' => null];
                    }
                }

                return $out;
            }
        };
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

    #[Test]
    public function aFirstRequestFetchesEverySourceOnceAndAsksGithubLittle(): void
    {
        $net = self::net();
        $doc = (new Feed($this->dir, $net))->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));

        self::assertSame('server', $doc['mode']);
        self::assertFalse($doc['stale']);
        self::assertSame('8.6', $doc['newest']);
        self::assertSame(2, $net->calls, 'one round for the fixed sources, one for tags and wiki');
        $github = array_filter($net->asked, static fn (string $id): bool => str_starts_with($id, 'tags-'));
        self::assertSame(['tags-8', 'tags-9'], array_values($github), 'one call per live major and the next');
        self::assertContains('wiki-8.7', $net->asked);
        $s = self::sources($doc);
        self::assertTrue($s['states']['ok']);
        self::assertTrue($s['wiki-8.7']['ok'], 'no page yet is an answer, not an outage');
        self::assertFalse($s['tags-9']['ok']);
        self::assertTrue($s['endoflife']['browser']);
        self::assertFalse($s['states']['browser']);
    }

    #[Test]
    public function aFreshCacheIsServedWithoutFetching(): void
    {
        $net = self::net();
        $feed = new Feed($this->dir, $net);
        $feed->get(new DateTimeImmutable('2026-11-19T22:00:00Z'));
        $doc = $feed->get(new DateTimeImmutable('2026-11-19T22:09:00Z'));

        self::assertSame(2, $net->calls);
        self::assertSame(540, $doc['age_seconds']);
        $feed->get(new DateTimeImmutable('2026-11-19T22:10:00Z'));
        self::assertSame(4, $net->calls, 'ten minutes on, it refreshes');
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
