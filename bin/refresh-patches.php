<?php

declare(strict_types=1);

/**
 * Refreshes the latest_patch and latest_patch_checked fields of the PHP,
 * Laravel, and Node versions in policy/policy.json from the upstream feeds.
 *
 * Feeds:
 *   PHP         https://www.php.net/releases/active.php
 *   Laravel     https://repo.packagist.org/p2/laravel/framework.json
 *   Node        https://nodejs.org/dist/index.json
 *   Cross-check https://endoflife.date/api/v1/products/php/
 *
 * Usage:
 *   php bin/refresh-patches.php [--dry-run] [--offline <dir>] [--policy <path>]
 *
 *   --dry-run        Print what would change without writing the policy.
 *   --offline <dir>  Read the feeds from <dir> instead of fetching them.
 *                    Expects php.json, laravel.json, node.json, and
 *                    endoflife-php.json in <dir>.
 *   --policy <path>  Path to the policy file (default: policy/policy.json).
 *
 * Exit codes:
 *   0  nothing to change (or --dry-run with nothing to change)
 *   3  the policy was updated (or --dry-run with changes to show)
 *   1  a feed could not be fetched or parsed
 *
 * The endoflife.date feed is a cross-check only: if it is missing or
 * disagrees with php.net, a warning is printed but the run continues,
 * because php.net is the source of record for PHP.
 */

use Composer\Semver\Comparator;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Fetch a URL with curl.
 *
 * @throws RuntimeException when the request fails or returns an error status
 */
function fetchUrl(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("Could not initialise curl for {$url}");
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'creed-refresh-patches/1.0',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Could not fetch {$url}: {$error}");
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("HTTP {$status} fetching {$url}");
    }

    return (string) $body;
}

/**
 * Load a feed from the offline directory or fetch it from its URL.
 *
 * @throws RuntimeException when the feed cannot be read
 */
function loadFeed(string $file, string $url, ?string $offlineDir): string
{
    if ($offlineDir !== null) {
        $path = $offlineDir . '/' . $file;
        if (!is_file($path)) {
            throw new RuntimeException("Offline feed not found: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read offline feed: {$path}");
        }

        return $contents;
    }

    return fetchUrl($url);
}

/**
 * Read a string property from a decoded JSON object, or null.
 */
function jsonStr(object $obj, string $key): ?string
{
    $vars = get_object_vars($obj);
    $value = $vars[$key] ?? null;

    return is_string($value) ? $value : null;
}

/**
 * Read a child object from a decoded JSON object, or null.
 */
function jsonChild(object $parent, string $key): ?stdClass
{
    $vars = get_object_vars($parent);
    $child = $vars[$key] ?? null;

    return $child instanceof stdClass ? $child : null;
}

/**
 * The highest of a list of plain X.Y[.Z] versions, or null if there is none.
 *
 * @param list<string> $versions
 */
function highestVersion(array $versions): ?string
{
    $best = null;
    foreach ($versions as $version) {
        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
            continue;
        }
        if ($best === null || Comparator::greaterThan($version, $best)) {
            $best = $version;
        }
    }

    return $best;
}

/**
 * Parse the php.net active releases feed into branch => latest version.
 *
 * @return array<string, string>
 *
 * @throws RuntimeException when the feed is not the expected shape
 */
function parsePhpFeed(string $json): array
{
    $data = json_decode($json, false);
    if (!is_object($data)) {
        throw new RuntimeException('php.net feed: expected a JSON object');
    }

    $latest = [];
    foreach (get_object_vars($data) as $branches) {
        if (!is_object($branches)) {
            continue;
        }
        foreach (get_object_vars($branches) as $branch => $info) {
            if (!is_object($info)) {
                continue;
            }
            $version = jsonStr($info, 'version');
            if ($version !== null && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
                $latest[$branch] = $version;
            }
        }
    }

    if ($latest === []) {
        throw new RuntimeException('php.net feed: no versions found');
    }

    return $latest;
}

/**
 * Parse the Packagist laravel/framework feed into major => latest stable.
 *
 * Major versions are numeric, so the keys are integers at runtime.
 *
 * @return array<int|string, string>
 *
 * @throws RuntimeException when the feed is not the expected shape
 */
function parseLaravelFeed(string $json): array
{
    $data = json_decode($json, false);
    if (!is_object($data)) {
        throw new RuntimeException('Packagist feed: expected a JSON object');
    }
    $packages = jsonChild($data, 'packages');
    if ($packages === null) {
        throw new RuntimeException('Packagist feed: missing "packages" object');
    }
    $framework = get_object_vars($packages);
    $versions = $framework['laravel/framework'] ?? null;
    if (!is_array($versions)) {
        throw new RuntimeException('Packagist feed: missing "laravel/framework" versions');
    }

    /** @var array<int|string, list<string>> $byMajor */
    $byMajor = [];
    foreach ($versions as $entry) {
        if (!is_object($entry)) {
            continue;
        }
        $version = jsonStr($entry, 'version');
        if ($version === null) {
            continue;
        }
        $plain = preg_replace('/^v/', '', $version);
        if ($plain === null) {
            continue;
        }
        if (preg_match('/^(\d+)\.\d+(\.\d+)?$/', $plain, $m) !== 1) {
            continue; // not a stable X.Y[.Z] release
        }
        $major = (string) $m[1];
        $byMajor[$major][] = $plain;
    }

    /** @var array<int|string, string> $latest */
    $latest = [];
    foreach ($byMajor as $major => $candidates) {
        $best = highestVersion($candidates);
        if ($best !== null) {
            $latest[$major] = $best;
        }
    }

    if ($latest === []) {
        throw new RuntimeException('Packagist feed: no versions found');
    }

    return $latest;
}

/**
 * Parse the nodejs.org dist index into major => latest version.
 *
 * Major versions are numeric, so the keys are integers at runtime.
 *
 * @return array<int|string, string>
 *
 * @throws RuntimeException when the feed is not the expected shape
 */
function parseNodeFeed(string $json): array
{
    $data = json_decode($json, false);
    if (!is_array($data)) {
        throw new RuntimeException('nodejs.org feed: expected a JSON array');
    }

    /** @var array<int|string, list<string>> $byMajor */
    $byMajor = [];
    foreach ($data as $entry) {
        if (!is_object($entry)) {
            continue;
        }
        $version = jsonStr($entry, 'version');
        if ($version === null) {
            continue;
        }
        $plain = preg_replace('/^v/', '', $version);
        if ($plain === null) {
            continue;
        }
        if (preg_match('/^(\d+)\.\d+\.\d+$/', $plain, $m) !== 1) {
            continue; // not a stable X.Y.Z release
        }
        $major = (string) $m[1];
        $byMajor[$major][] = $plain;
    }

    /** @var array<int|string, string> $latest */
    $latest = [];
    foreach ($byMajor as $major => $candidates) {
        $best = highestVersion($candidates);
        if ($best !== null) {
            $latest[$major] = $best;
        }
    }

    if ($latest === []) {
        throw new RuntimeException('nodejs.org feed: no versions found');
    }

    return $latest;
}

/**
 * Parse the endoflife.date PHP feed into branch => latest version.
 *
 * @return array<string, string>
 *
 * @throws RuntimeException when the feed is not the expected shape
 */
function parseEndOfLifeFeed(string $json): array
{
    $data = json_decode($json, false);
    if (!is_object($data)) {
        throw new RuntimeException('endoflife.date feed: expected a JSON object');
    }
    $result = jsonChild($data, 'result');
    if ($result === null) {
        throw new RuntimeException('endoflife.date feed: missing "result" object');
    }
    $releases = get_object_vars($result);
    $releases = $releases['releases'] ?? null;
    if (!is_array($releases)) {
        throw new RuntimeException('endoflife.date feed: missing "releases" array');
    }

    $latest = [];
    foreach ($releases as $release) {
        if (!is_object($release)) {
            continue;
        }
        $branch = jsonStr($release, 'name');
        $latestEntry = jsonChild($release, 'latest');
        if ($branch === null || $latestEntry === null) {
            continue;
        }
        $version = jsonStr($latestEntry, 'name');
        if ($version !== null && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
            $latest[$branch] = $version;
        }
    }

    return $latest;
}

/**
 * Encode a string as a JSON string literal, unescaping slashes and unicode.
 *
 * @throws RuntimeException when the string cannot be encoded
 */
function jsonString(string $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Could not encode a JSON string');
    }

    return $encoded;
}

/**
 * Encode a decoded JSON tree (stdClass objects and arrays) in the policy's
 * stable format: 4-space indent, objects kept as objects (an empty object
 * stays "{}" and never becomes "[]"), unescaped slashes and unicode.
 */
function jsonEncodeStable(mixed $value, int $depth = 0): string
{
    $indent = str_repeat('    ', $depth);
    $childIndent = str_repeat('    ', $depth + 1);

    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        $encoded = json_encode($value);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode a JSON number');
        }

        return $encoded;
    }
    if (is_string($value)) {
        return jsonString($value);
    }
    if (is_object($value)) {
        $vars = get_object_vars($value);
        if ($vars === []) {
            return '{}';
        }
        $parts = [];
        foreach ($vars as $key => $child) {
            $parts[] = $childIndent . jsonString((string) $key) . ': ' . jsonEncodeStable($child, $depth + 1);
        }

        return "{\n" . implode(",\n", $parts) . "\n" . $indent . '}';
    }
    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }
        $parts = [];
        foreach ($value as $child) {
            $parts[] = $childIndent . jsonEncodeStable($child, $depth + 1);
        }

        return "[\n" . implode(",\n", $parts) . "\n" . $indent . ']';
    }

    return 'null';
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$root = dirname(__DIR__);
$dryRun = false;
$offlineDir = null;
$policyPath = $root . '/policy/policy.json';

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--offline') {
        $i++;
        if ($i >= count($args)) {
            fwrite(STDERR, "--offline requires a directory\n");
            exit(1);
        }
        $offlineDir = $args[$i];
    } elseif ($arg === '--policy') {
        $i++;
        if ($i >= count($args)) {
            fwrite(STDERR, "--policy requires a path\n");
            exit(1);
        }
        $policyPath = $args[$i];
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}

$feeds = [
    'php' => ['file' => 'php.json', 'url' => 'https://www.php.net/releases/active.php'],
    'laravel' => ['file' => 'laravel.json', 'url' => 'https://repo.packagist.org/p2/laravel/framework.json'],
    'node' => ['file' => 'node.json', 'url' => 'https://nodejs.org/dist/index.json'],
    'endoflife' => ['file' => 'endoflife-php.json', 'url' => 'https://endoflife.date/api/v1/products/php/'],
];

// The primary feeds are required; a failure here stops the run.
try {
    $phpJson = loadFeed($feeds['php']['file'], $feeds['php']['url'], $offlineDir);
    $laravelJson = loadFeed($feeds['laravel']['file'], $feeds['laravel']['url'], $offlineDir);
    $nodeJson = loadFeed($feeds['node']['file'], $feeds['node']['url'], $offlineDir);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// The endoflife feed is a cross-check only; a failure here is a warning.
$endOfLifeJson = null;
try {
    $endOfLifeJson = loadFeed($feeds['endoflife']['file'], $feeds['endoflife']['url'], $offlineDir);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'warning: no endoflife.date cross-check: ' . $e->getMessage() . "\n");
}

try {
    $phpLatest = parsePhpFeed($phpJson);
    $laravelLatest = parseLaravelFeed($laravelJson);
    $nodeLatest = parseNodeFeed($nodeJson);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$endOfLifeLatest = null;
if ($endOfLifeJson !== null) {
    try {
        $endOfLifeLatest = parseEndOfLifeFeed($endOfLifeJson);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'warning: could not parse endoflife.date cross-check: ' . $e->getMessage() . "\n");
    }
}

if (!is_file($policyPath)) {
    fwrite(STDERR, "Policy file not found: {$policyPath}\n");
    exit(1);
}
$raw = file_get_contents($policyPath);
if ($raw === false) {
    fwrite(STDERR, "Could not read policy file: {$policyPath}\n");
    exit(1);
}
$data = json_decode($raw, false);
if (!is_object($data)) {
    fwrite(STDERR, "Policy file is not valid JSON: {$policyPath}\n");
    exit(1);
}
$components = jsonChild($data, 'components');
if ($components === null) {
    fwrite(STDERR, "Policy has no \"components\" object\n");
    exit(1);
}

// Cross-check PHP against endoflife.date (a warning, never a failure).
if ($endOfLifeLatest !== null) {
    $phpComponent = jsonChild($components, 'php');
    if ($phpComponent !== null) {
        $phpVersions = jsonChild($phpComponent, 'versions');
        if ($phpVersions !== null) {
            foreach (array_keys(get_object_vars($phpVersions)) as $branch) {
                $fromPhpNet = $phpLatest[$branch] ?? null;
                $fromEndOfLife = $endOfLifeLatest[$branch] ?? null;
                if ($fromPhpNet !== null && $fromEndOfLife !== null && $fromPhpNet !== $fromEndOfLife) {
                    fwrite(STDERR, "warning: PHP {$branch} differs: php.net says {$fromPhpNet}, endoflife.date says {$fromEndOfLife}\n");
                }
            }
        }
    }
}

$today = date('Y-m-d');
$changes = [];
$updates = [
    'php' => $phpLatest,
    'laravel' => $laravelLatest,
    'node' => $nodeLatest,
];

foreach ($updates as $component => $latestMap) {
    $componentObj = jsonChild($components, $component);
    if ($componentObj === null) {
        continue;
    }
    $versions = jsonChild($componentObj, 'versions');
    if ($versions === null) {
        continue;
    }
    foreach (array_keys(get_object_vars($versions)) as $versionKey) {
        $versionKey = (string) $versionKey;
        $latest = $latestMap[$versionKey] ?? null;
        if ($latest === null) {
            fwrite(STDERR, "warning: {$component} {$versionKey} not in feed, leaving as-is\n");
            continue; // not in the feed (for example, an EOL branch) - leave it
        }
        $entry = jsonChild($versions, $versionKey);
        if ($entry === null) {
            continue;
        }
        $current = jsonStr($entry, 'latest_patch');
        if ($current === $latest) {
            continue; // already current
        }
        $entry->latest_patch = $latest;
        $entry->latest_patch_checked = $today;
        $changes[] = "{$component} {$versionKey}: " . ($current ?? '(none)') . " -> {$latest}";
    }
}

if ($changes === []) {
    echo "No changes.\n";
    exit(0);
}

foreach ($changes as $change) {
    echo ($dryRun ? 'would update ' : 'updated ') . $change . "\n";
}

if (!$dryRun) {
    $encoded = jsonEncodeStable($data) . "\n";
    if (file_put_contents($policyPath, $encoded) === false) {
        fwrite(STDERR, "Could not write policy file: {$policyPath}\n");
        exit(1);
    }
}

exit(3);
