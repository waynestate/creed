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
 *    at most every TTL_MINUTES: about 12 an hour. php-src master's
 *    php_version.h comes from raw.githubusercontent.com, outside that budget,
 *    and so do the frameworks' CI workflows (one per framework major, read in
 *    the same round as the tags).
 *  - The php.net wiki is asked one page a second, never in parallel. Its
 *    index names every release page; supported and future releases are
 *    re-read each refresh, a closed release's page once (WIKI_BACKFILL new
 *    ones per refresh until all are kept).
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

    /**
     * Where each framework's own CI says which PHP lines it tests: the GitHub repo, the
     * workflow file under .github/workflows/, and the branch a major lives on (%s is the
     * major Packagist named). Config, not a version list: the lines come out of the file.
     * Read from raw.githubusercontent.com, outside GitHub's 60-an-hour API budget.
     * Paths confirmed 2026-11-20 on laravel/framework 12.x and 13.x, cakephp/cakephp 4.x
     * and 5.x, slimphp/Slim 3.x and 4.x.
     */
    public const WORKFLOWS = [
        'laravel/framework' => ['repo' => 'laravel/framework', 'file' => 'tests.yml', 'branch' => '%s.x'],
        'cakephp/cakephp' => ['repo' => 'cakephp/cakephp', 'file' => 'ci.yml', 'branch' => '%s.x'],
        'slim/slim' => ['repo' => 'slimphp/Slim', 'file' => 'tests.yml', 'branch' => '%s.x'],
    ];

    /**
     * Frameworks the department is phasing out (D-124; CREED handbook, Code, "Frameworks").
     * A label on the page, not a gate: their fit data stays exactly as Packagist says.
     */
    public const LEGACY = [
        'cakephp/cakephp' => 'New apps use raw PHP or Laravel (D-124).',
        'slim/slim' => 'New apps use raw PHP or Laravel (D-124).',
    ];

    /** The wiki is a volunteer-run DokuWiki: one request a second at most, never in parallel. */
    public const WIKI_GAP_SECONDS = 1.0;

    /** Timetables of closed releases never change: fetched once and kept. At most this many new ones per refresh. */
    public const WIKI_BACKFILL = 3;

    /** @var array<string, true> closed-release timetables fetched this refresh for the first time */
    private array $backfill = [];

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
        $state = $this->collect($first, $this->http->fetchAll(array_map(static fn(array $p): string => $p['url'], $first)), $state, $stamp);
        $late = $this->planLate($state, substr($stamp, 0, 10));
        $github = array_filter($late, static fn(array $p): bool => $p['group'] !== 'wiki');
        $wiki = array_diff_key($late, $github);
        $state = $this->collect($github, $this->http->fetchAll(array_map(static fn(array $p): string => $p['url'], $github)), $state, $stamp);
        $state = $this->collect($wiki, $this->http->fetchPaced(array_map(static fn(array $p): string => $p['url'], $wiki), self::WIKI_GAP_SECONDS), $state, $stamp);

        // Drop sources this refresh no longer asks for (a retired major). Timetables the
        // wiki index still lists are kept even when not fetched: closed ones never change.
        $listed = $this->listedPages($state);
        $state = array_filter($state, static fn(array $s, string $id): bool => isset($first[$id]) || isset($late[$id])
            || (str_starts_with($id, 'wiki-') && ($listed === null || isset($listed[substr($id, 5)]))), ARRAY_FILTER_USE_BOTH);

        $in = [
            'states' => $state['states']['parsed'] ?? null,
            'active' => $state['active']['parsed'] ?? null,
            'atom' => $state['atom']['parsed'] ?? null,
            'endoflife' => $state['endoflife']['parsed'] ?? null,
            'master' => $state['master']['parsed'] ?? null,
            'wikiPages' => $state['wiki-index']['parsed'] ?? null,
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
            if (str_starts_with($id, 'wiki-') && $id !== 'wiki-index' && ($s['ok_at'] ?? null) !== null) {
                $in['wiki'][substr($id, 5)] = $s['parsed'] ?? null;
            }
        }
        foreach (self::FRAMEWORKS as $package => $name) {
            // Each major's CI workflow: the lines it runs (null when it was never read) and where it lives.
            $workflows = [];
            foreach ($this->planWorkflows($state) as $id => $p) {
                if (str_starts_with($id, 'ci-' . $package . '@')) {
                    $workflows[substr($id, strlen('ci-' . $package . '@'))] = ['lines' => $state[$id]['parsed'] ?? null, 'url' => $p['link']];
                }
            }
            $in['frameworks'][$package] = [
                'name' => $name,
                'url' => 'https://packagist.org/packages/' . $package,
                'majors' => $state['packagist-' . $package]['parsed'] ?? null,
                'workflows' => $workflows,
                'lifecycle' => isset(self::LEGACY[$package]) ? 'legacy' : null,
                'lifecycle_note' => self::LEGACY[$package] ?? null,
            ];
        }

        $sources = [];
        foreach (array_diff_key($first + $late, $this->backfill) as $id => $p) {
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
        // Closed releases' timetables: one line for all of them. Read once, so never stale.
        $live = array_diff_key($late, $this->backfill);
        $closed = $listed === null ? 0 : count(array_filter(array_keys($listed), static fn(string $b): bool => !isset($live['wiki-' . $b])));
        if ($closed > 0) {
            $read = array_filter($state, static fn(array $s, string $id): bool => str_starts_with($id, 'wiki-') && $id !== 'wiki-index' && !isset($live[$id]) && is_string($s['ok_at'] ?? null), ARRAY_FILTER_USE_BOTH);
            $okAt = $read === [] ? null : min(array_map(static fn(array $s): string => (string) $s['ok_at'], $read));
            $sources[] = [
                'id' => 'wiki-archive',
                'group' => 'wiki',
                'label' => 'php.net wiki: ' . count($read) . ' of ' . $closed . ' closed-release timetables, read once and kept',
                'url' => 'https://wiki.php.net/todo',
                'ok' => $read !== [],
                'checked_at' => $okAt ?? $stamp,
                'ok_at' => $okAt,
                'stale' => $read === [],
                'error' => null,
                'browser' => false,
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
                'parse' => static fn(string $b): mixed => Parse::states($b)],
            'active' => ['url' => 'https://www.php.net/releases/active.php', 'link' => 'https://www.php.net/downloads.php', 'label' => 'php.net releases', 'group' => 'php.net', 'browser' => false,
                'parse' => static fn(string $b): mixed => Parse::active($b)],
            'atom' => ['url' => 'https://www.php.net/feed.atom', 'link' => 'https://www.php.net/', 'label' => 'php.net news', 'group' => 'php.net', 'browser' => false,
                'parse' => static fn(string $b): mixed => ($e = Parse::atom($b)) === null ? null : array_map(
                    static fn(array $x): array => ['text' => mb_substr($x['text'], 0, 1500)] + $x,
                    array_slice($e, 0, self::ATOM_KEEP),
                )],
            'endoflife' => ['url' => 'https://endoflife.date/api/v1/products/php', 'link' => 'https://endoflife.date/php', 'label' => 'endoflife.date', 'group' => 'cross-check', 'browser' => true,
                'parse' => static fn(string $b): mixed => Parse::endoflife($b)],
            // php-src master says which version comes next; raw files are not the rate-limited API.
            'master' => ['url' => 'https://raw.githubusercontent.com/php/php-src/master/main/php_version.h', 'link' => Merge::MASTER_LINK, 'label' => 'php-src master version', 'group' => 'php-src', 'browser' => false,
                'parse' => static fn(string $b): mixed => Parse::phpVersionH($b)],
            // The wiki's index of release pages: a new timetable shows up here first.
            'wiki-index' => ['url' => 'https://wiki.php.net/todo?do=export_raw', 'link' => Merge::INDEX_LINK, 'label' => 'php.net wiki: release pages index', 'group' => 'wiki', 'browser' => false,
                'parse' => static fn(string $b): mixed => Parse::wikiIndex($b)],
        ];
        foreach (self::FRAMEWORKS as $package => $name) {
            $plan['packagist-' . $package] = ['url' => 'https://repo.packagist.org/p2/' . $package . '.json', 'link' => 'https://packagist.org/packages/' . $package, 'label' => 'Packagist: ' . $name, 'group' => 'frameworks', 'browser' => false,
                'parse' => static fn(string $b): mixed => Parse::packagist($b, $package)];
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
        $this->backfill = [];
        // The frameworks' CI workflows need only Packagist's majors, not the branch list.
        $ci = $this->planWorkflows($state);
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
            return $ci;
        }
        $all = array_keys($branches);
        usort($all, static fn(string $a, string $b): int => version_compare($b, $a));
        [$newMajor, $newMinor] = array_map('intval', explode('.', $all[0]));
        $majors = [];
        foreach ($branches as $branch => $live) {
            if ($live) {
                $majors[(int) explode('.', (string) $branch)[0]] = true;
            }
        }
        $majors[$newMajor + 1] = true;
        ksort($majors);

        $master = $state['master']['parsed'] ?? null;
        if (is_array($master) && is_int($master['major'] ?? null)) {
            $majors[$master['major']] = true;
        }
        ksort($majors);

        $plan = [];
        foreach (array_keys($majors) as $major) {
            $plan['tags-' . $major] = ['url' => 'https://api.github.com/repos/php/php-src/git/matching-refs/tags/php-' . $major . '.', 'link' => 'https://github.com/php/php-src/tags', 'label' => 'php-src tags (PHP ' . $major . ')', 'group' => 'php-src', 'browser' => true,
                'parse' => static fn(string $b): mixed => Parse::tags($b)];
        }

        // Timetables: every page the wiki's index lists, none guessed. A supported or future
        // release is re-read on every refresh; a closed one is read once and kept.
        $this->backfill = [];
        $missing = 0;
        foreach ($this->listedPages($state) ?? [] as $branch => $page) {
            $id = 'wiki-' . $branch;
            $open = ($branches[$branch] ?? false) || version_compare($branch, $all[0], '>');
            if (!$open) {
                if (($state[$id]['ok_at'] ?? null) !== null || $missing >= self::WIKI_BACKFILL) {
                    continue;
                }
                $missing++;
                $this->backfill[$id] = true;
            }
            $plan[$id] = ['url' => 'https://wiki.php.net/todo/' . $page . '?do=export_raw', 'link' => 'https://wiki.php.net/todo/' . $page, 'label' => 'php.net wiki: ' . $branch . ' timetable', 'group' => 'wiki', 'browser' => false,
                'parse' => static fn(string $b): mixed => Parse::wiki($b)];
        }

        return $plan + $ci;
    }

    /**
     * One CI workflow read per framework major Packagist named: `ci-<package>@<major>`.
     *
     * @param array<string, array<string, mixed>> $state
     *
     * @return array<string, array{url: string, link: string, label: string, group: string, browser: bool, parse: callable(string): mixed}>
     */
    private function planWorkflows(array $state): array
    {
        $plan = [];
        foreach (self::WORKFLOWS as $package => $wf) {
            $majors = $state['packagist-' . $package]['parsed'] ?? null;
            foreach (is_array($majors) ? $majors : [] as $m) {
                if (!is_array($m) || !is_string($m['major'] ?? null)) {
                    continue;
                }
                $branch = sprintf($wf['branch'], $m['major']);
                $path = $wf['repo'] . '/' . $branch . '/.github/workflows/' . $wf['file'];
                $plan['ci-' . $package . '@' . $m['major']] = [
                    'url' => 'https://raw.githubusercontent.com/' . $path,
                    'link' => 'https://github.com/' . $wf['repo'] . '/blob/' . $branch . '/.github/workflows/' . $wf['file'],
                    'label' => 'GitHub: ' . self::FRAMEWORKS[$package] . ' ' . $branch . ' CI workflow',
                    'group' => 'framework-ci',
                    'browser' => false,
                    'parse' => static fn(string $b): mixed => Parse::workflowPhp($b),
                ];
            }
        }

        return $plan;
    }

    /**
     * The wiki index's pages as branch => page name, newest first; null when the index was never read.
     *
     * @param array<string, array<string, mixed>> $state
     *
     * @return ?array<string, string>
     */
    private function listedPages(array $state): ?array
    {
        $index = $state['wiki-index']['parsed'] ?? null;
        if (!is_array($index)) {
            return null;
        }
        $out = [];
        foreach ($index as $p) {
            if (is_array($p) && is_string($p['branch'] ?? null) && is_string($p['page'] ?? null)) {
                $out[$p['branch']] = $p['page'];
            }
        }

        return $out;
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
            if (str_starts_with($id, 'wiki-') && $id !== 'wiki-index' && $r['status'] === 404) {
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
