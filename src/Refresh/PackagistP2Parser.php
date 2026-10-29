<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * Parses a Packagist p2 metadata feed (for example
 * https://repo.packagist.org/p2/laravel/framework.json) into
 * major => latest stable version.
 *
 * Feed shape: {"packages": {"laravel/framework": [{"version": "v13.39.0",
 * ...}, ...]}, ...}
 */
final class PackagistP2Parser
{
    /**
     * @return array<int|string, string> keyed by major, for example "13"
     *
     * @throws RuntimeException when the feed is not the expected shape
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, false);
        if (!is_object($data)) {
            throw new RuntimeException('Packagist feed: expected a JSON object');
        }
        $packages = JsonFields::child($data, 'packages');
        if ($packages === null) {
            throw new RuntimeException('Packagist feed: missing "packages" object');
        }
        $framework = get_object_vars($packages)['laravel/framework'] ?? null;
        if (!is_array($framework)) {
            throw new RuntimeException('Packagist feed: missing "laravel/framework" versions');
        }

        /** @var array<string, list<string>> $byMajor */
        $byMajor = [];
        foreach ($framework as $entry) {
            if (!is_object($entry)) {
                continue;
            }
            $version = JsonFields::str($entry, 'version');
            if ($version === null) {
                continue;
            }
            $plain = Versions::stripV($version);
            if (preg_match('/^(\d+)\.\d+(\.\d+)?$/', $plain, $m) !== 1) {
                continue; // not a stable X.Y[.Z] release
            }
            $byMajor[$m[1]][] = $plain;
        }

        $latest = [];
        foreach ($byMajor as $major => $candidates) {
            $best = Versions::highest($candidates);
            if ($best !== null) {
                $latest[(string) $major] = $best;
            }
        }

        if ($latest === []) {
            throw new RuntimeException('Packagist feed: no versions found');
        }

        return $latest;
    }
}
