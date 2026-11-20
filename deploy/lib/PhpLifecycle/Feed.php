<?php

declare(strict_types=1);

namespace Waynestate\Creed\Site\PhpLifecycle;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The server side of /php: fetch the sources, keep the last good answer of
 * each, merge, and cache the merged document in a file.
 *
 *  - A cached document younger than TTL_MINUTES is served as it is.
 *  - Older, one request takes a non-blocking lock and refreshes; everyone
 *    else is served the cached copy meanwhile. At most one refresh at a time.
 *  - A source that fails keeps its last good answer, marked stale with the
 *    time it was last read. A source never read shows as down. Nothing is
 *    filled in for it.
 *  - GitHub's unauthenticated budget is 60 calls an hour per address. A
 *    refresh makes one call per live PHP major plus one for the next major,
 *    at most every TTL_MINUTES: about 12 an hour.
 *
 * Adding a source (the Citadel images row, say) is one entry in plan() or
 * planLate(), one parser in Parse and one input to Merge::build().
 */
final class Feed
{
    public const TTL_MINUTES = 10;

    /** The promise on the page: never more than this far behind the sources. */
    public const MAX_AGE_MINUTES = 30;

    /** How many news entries are kept: months of releases, a fraction of the 1.5 MB feed. */
    private const ATOM_KEEP = 40;

    /** Framework rows: Packagist package => display name. */
    public const FRAMEWORKS = [
        'laravel/framework' => 'Laravel',
        'cakephp/cakephp' => 'CakePHP',
        'slim/slim' => 'Slim',
    ];

    public function __construct(
        private readonly string $dir,
        private readonly Http $http,
    ) {}

    /**
     * The document for the page, refreshed first when it is due.
     *
     * @return array<string, mixed>
     */
    public function get(DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $cache = $this->read();
        $builtAt = isset($cache['doc']['generated_at']) && is_string($cache['doc']['generated_at'])
            ? new DateTimeImmutable($cache['doc']['generated_at'])
            : null;
        $due = $builtAt === null || $now->getTimestamp() - $builtAt->getTimestamp() >= self::TTL_MINUTES * 60;

        if ($due && $this->ensureDir()) {
            $lock = @fopen($this->dir . '/refresh.lock', 'c');
            if ($lock !== false) {
                // Nothing cached yet: wait for whoever is refreshing rather than answer empty-handed.
                $got = flock($lock, LOCK_EX | ($cache === null ? 0 : LOCK_NB));
                if ($got) {
                    $fresh = $this->read();
                    $freshAt = isset($fresh['doc']['generated_at']) && is_string($fresh['doc']['generated_at']) ? new DateTimeImmutable($fresh['doc']['generated_at']) : null;
                    if ($freshAt === null || $now->getTimestamp() - $freshAt->getTimestamp() >= self::TTL_MINUTES * 60) {
                        $cache = $this->refresh($fresh, $now);
                        $this->write($cache);
                    } else {
                        $cache = $fresh;
                    }
                    flock($lock, LOCK_UN);
                }
                fclose($lock);
            }
        }

        if ($cache === null) {
            $cache = $this->refresh(null, $now);
        }
        $doc = is_array($cache['doc'] ?? null) ? $cache['doc'] : [];
        $built = isset($doc['generated_at']) && is_string($doc['generated_at']) ? new DateTimeImmutable($doc['generated_at']) : $now;
        $age = max(0, $now->getTimestamp() - $built->getTimestamp());
        $doc['mode'] = 'server';
        $doc['served_at'] = $now->format('Y-m-d\TH:i:s\Z');
        $doc['age_seconds'] = $age;
        $doc['stale'] = $age > self::MAX_AGE_MINUTES * 60;
        $doc['ttl_minutes'] = self::TTL_MINUTES;
        $doc['max_age_minutes'] = self::MAX_AGE_MINUTES;

        return $doc;
    }

    /**
     * Fetch everything, keep last good answers, merge.
     *
     * @param ?array<string, mixed> $previous
     *
     * @return array{sources: array<string, array<string, mixed>>, doc: array<string, mixed>}
     */
    public function refresh(?array $previous, DateTimeImmutable $now): array
    {
        /** @var array<string, array<string, mixed>> $state */
        $state = is_array($previous['sources'] ?? null) ? $previous['sources'] : [];
        $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $first = $this->plan();
        $state = $this->collect($first, $this->http->fetchAll(array_map(static fn (array $p): string => $p['url'], $first)), $state, $stamp);
        $late = $this->planLate($state, substr($stamp, 0, 10));
        $state = $this->collect($late, $this->http->fetchAll(array_map(static fn (array $p): string => $p['url'], $late)), $state, $stamp);

        // Drop sources this refresh no longer asks for (an old wiki page, a retired major).
        $state = array_intersect_key($state, $first + $late);

        $in = [
            'states' => $state['states']['parsed'] ?? null,
            'active' => $state['active']['parsed'] ?? null,
            'atom' => $state['atom']['parsed'] ?? null,
            'endoflife' => $state['endoflife']['parsed'] ?? null,
            'tags' => null,
            'wiki' => [],
            'frameworks' => [],
            // Stub: a platform source (Citadel's built images) fills this when one exists.
            'platforms' => [],
        ];
        foreach ($state as $id => $s) {
            if (str_starts_with($id, 'tags-') && is_array($s['parsed'] ?? null)) {
                $in['tags'] = array_merge($in['tags'] ?? [], $s['parsed']);
            }
            if (str_starts_with($id, 'wiki-')) {
                $in['wiki'][substr($id, 5)] = $s['parsed'] ?? null;
            }
        }
        foreach (self::FRAMEWORKS as $package => $name) {
            $in['frameworks'][$package] = [
                'name' => $name,
                'url' => 'https://packagist.org/packages/' . $package,
                'majors' => $state['packagist-' . $package]['parsed'] ?? null,
            ];
        }

        $sources = [];
        foreach ($first + $late as $id => $p) {
            $s = $state[$id] ?? [];
            $okAt = is_string($s['ok_at'] ?? null) ? $s['ok_at'] : null;
            $sources[] = [
                'id' => $id,
                'group' => $p['group'],
                'label' => $p['label'],
                'url' => $p['link'],
                'ok' => (bool) ($s['ok'] ?? false),
                'checked_at' => $s['checked_at'] ?? $stamp,
                'ok_at' => $okAt,
                'stale' => $okAt === null || $now->getTimestamp() - (new DateTimeImmutable($okAt))->getTimestamp() > self::MAX_AGE_MINUTES * 60,
                'error' => $s['error'] ?? null,
                'browser' => $p['browser'],
            ];
        }

        /** @phpstan-ignore argument.type (the cache holds what Parse returned; Merge re-checks shapes it relies on) */
        $doc = Merge::build($in, $now, $sources);

        return ['sources' => $state, 'doc' => $doc];
    }

    /**
     * The sources that need nothing else first.
     *
     * @return array<string, array{url: string, link: string, label: string, group: string, browser: bool, parse: callable(string): mixed}>
     */
    public function plan(): array
    {
        $plan = [
            'states' => ['url' => 'https://www.php.net/releases/states.php', 'link' => 'https://www.php.net/supported-versions.php', 'label' => 'php.net supported versions', 'group' => 'php.net', 'browser' => false,
                'parse' => static fn (string $b): mixed => Parse::states($b)],
            'active' => ['url' => 'https://www.php.net/releases/active.php', 'link' => 'https://www.php.net/downloads.php', 'label' => 'php.net releases', 'group' => 'php.net', 'browser' => false,
                'parse' => static fn (string $b): mixed => Parse::active($b)],
            'atom' => ['url' => 'https://www.php.net/feed.atom', 'link' => 'https://www.php.net/', 'label' => 'php.net news', 'group' => 'php.net', 'browser' => false,
                'parse' => static fn (string $b): mixed => ($e = Parse::atom($b)) === null ? null : array_map(
                    static fn (array $x): array => ['text' => mb_substr($x['text'], 0, 1500)] + $x,
                    array_slice($e, 0, self::ATOM_KEEP),
                )],
            'endoflife' => ['url' => 'https://endoflife.date/api/v1/products/php', 'link' => 'https://endoflife.date/php', 'label' => 'endoflife.date', 'group' => 'cross-check', 'browser' => true,
                'parse' => static fn (string $b): mixed => Parse::endoflife($b)],
        ];
        foreach (self::FRAMEWORKS as $package => $name) {
            $plan['packagist-' . $package] = ['url' => 'https://repo.packagist.org/p2/' . $package . '.json', 'link' => 'https://packagist.org/packages/' . $package, 'label' => 'Packagist: ' . $name, 'group' => 'frameworks', 'browser' => false,
                'parse' => static fn (string $b): mixed => Parse::packagist($b, $package)];
        }

        return $plan;
    }

    /**
     * The sources whose URLs come from the first round: php-src tags for each
     * live major and the next, and the wiki timetable for the next branches.
     *
     * @param array<string, array<string, mixed>> $state
     *
     * @return array<string, array{url: string, link: string, label: string, group: string, browser: bool, parse: callable(string): mixed}>
     */
    public function planLate(array $state, string $today): array
    {
        $branches = [];
        foreach (['states', 'endoflife'] as $id) {
            $parsed = $state[$id]['parsed'] ?? null;
            if (!is_array($parsed)) {
                continue;
            }
            $list = $id === 'endoflife' ? ($parsed['branches'] ?? []) : $parsed;
            foreach ($list as $branch => $info) {
                $live = is_array($info) && (($info['security_until'] ?? null) === null || $info['security_until'] >= $today);
                $branches[(string) $branch] = ($branches[(string) $branch] ?? false) || $live;
            }
            if ($branches !== []) {
                break;
            }
        }
        if ($branches === []) {
            return [];
        }
        $all = array_keys($branches);
        usort($all, static fn (string $a, string $b): int => version_compare($b, $a));
        [$newMajor, $newMinor] = array_map('intval', explode('.', $all[0]));
        $majors = [];
        foreach ($branches as $branch => $live) {
            if ($live) {
                $majors[(int) explode('.', (string) $branch)[0]] = true;
            }
        }
        $majors[$newMajor + 1] = true;
        ksort($majors);

        $plan = [];
        foreach (array_keys($majors) as $major) {
            $plan['tags-' . $major] = ['url' => 'https://api.github.com/repos/php/php-src/git/matching-refs/tags/php-' . $major . '.', 'link' => 'https://github.com/php/php-src/tags', 'label' => 'php-src tags (PHP ' . $major . ')', 'group' => 'php-src', 'browser' => true,
                'parse' => static fn (string $b): mixed => Parse::tags($b)];
        }
        foreach ([$newMajor . '.' . ($newMinor + 1), ($newMajor + 1) . '.0'] as $next) {
            $page = 'php' . str_replace('.', '', $next);
            $plan['wiki-' . $next] = ['url' => 'https://wiki.php.net/todo/' . $page . '?do=export_raw', 'link' => 'https://wiki.php.net/todo/' . $page, 'label' => 'php.net wiki: ' . $next . ' timetable', 'group' => 'wiki', 'browser' => false,
                'parse' => static fn (string $b): mixed => Parse::wiki($b)];
        }

        return $plan;
    }

    /**
     * @param array<string, array{url: string, link: string, label: string, group: string, browser: bool, parse: callable(string): mixed}> $plan
     * @param array<string, array{status: int, body: ?string, error: ?string}> $got
     * @param array<string, array<string, mixed>> $state
     *
     * @return array<string, array<string, mixed>>
     */
    private function collect(array $plan, array $got, array $state, string $stamp): array
    {
        foreach ($plan as $id => $p) {
            $r = $got[$id] ?? ['status' => 0, 'body' => null, 'error' => 'not fetched'];
            $s = $state[$id] ?? [];
            $s['checked_at'] = $stamp;
            // A wiki page that does not exist yet is an answer: no timetable published.
            if (str_starts_with($id, 'wiki-') && $r['status'] === 404) {
                $s += ['parsed' => null];
                $s['parsed'] = null;
                $s['ok'] = true;
                $s['ok_at'] = $stamp;
                $s['error'] = null;
                $state[$id] = $s;
                continue;
            }
            $parsed = $r['status'] === 200 && $r['body'] !== null ? ($p['parse'])($r['body']) : null;
            if ($parsed !== null) {
                $s['parsed'] = $parsed;
                $s['ok'] = true;
                $s['ok_at'] = $stamp;
                $s['error'] = null;
            } else {
                $s['ok'] = false;
                $s['error'] = $r['error'] ?? ($r['status'] === 200 ? 'unreadable answer' : 'HTTP ' . $r['status']);
            }
            $state[$id] = $s;
        }

        return $state;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function read(): ?array
    {
        $file = $this->dir . '/feed.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function write(array $cache): void
    {
        $tmp = $this->dir . '/feed.json.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, $this->dir . '/feed.json');
        }
    }

    private function ensureDir(): bool
    {
        return is_dir($this->dir) || @mkdir($this->dir, 0700, true) || is_dir($this->dir);
    }
}
