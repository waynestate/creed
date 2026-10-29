<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * Parses an endoflife.date product feed (for example
 * https://endoflife.date/api/v1/products/php/) into its release list.
 *
 * Feed shape: {"result": {"name": "php", "releases": [{"name": "8.5",
 * "isEol": false, "eoasFrom": "2027-12-31", "eolFrom": "2029-12-31",
 * "latest": {"name": "8.5.12"}}, ...]}, ...}
 */
final class EndOfLifeParser
{
    /**
     * @return list<Release>
     *
     * @throws RuntimeException when the feed is not the expected shape
     */
    public static function parseReleases(string $json): array
    {
        $data = json_decode($json, false);
        if (!is_object($data)) {
            throw new RuntimeException('endoflife.date feed: expected a JSON object');
        }
        $result = JsonFields::child($data, 'result');
        if ($result === null) {
            throw new RuntimeException('endoflife.date feed: missing "result" object');
        }
        $releases = get_object_vars($result)['releases'] ?? null;
        if (!is_array($releases)) {
            throw new RuntimeException('endoflife.date feed: missing "releases" array');
        }

        $lines = [];
        foreach ($releases as $release) {
            if (!is_object($release)) {
                continue;
            }
            $name = JsonFields::str($release, 'name');
            $isEol = JsonFields::bool($release, 'isEol');
            if ($name === null || $isEol === null) {
                continue;
            }
            $latestEntry = JsonFields::child($release, 'latest');
            $lines[] = new Release(
                name: $name,
                isEol: $isEol,
                eolFrom: Dates::normalize(JsonFields::str($release, 'eolFrom')),
                eoasFrom: Dates::normalize(JsonFields::str($release, 'eoasFrom')),
                isLts: JsonFields::bool($release, 'isLts'),
                latest: $latestEntry === null ? null : JsonFields::str($latestEntry, 'name'),
            );
        }

        return $lines;
    }

    /**
     * Index a release list by line name.
     *
     * @param list<Release> $releases
     *
     * @return array<int|string, Release>
     */
    public static function byName(array $releases): array
    {
        $index = [];
        foreach ($releases as $release) {
            $index[$release->name] = $release;
        }

        return $index;
    }

    /**
     * The latest version per line, where the feed carries one.
     *
     * @param list<Release> $releases
     *
     * @return array<int|string, string>
     */
    public static function latestByLine(array $releases): array
    {
        $latest = [];
        foreach ($releases as $release) {
            if ($release->latest !== null) {
                $latest[$release->name] = $release->latest;
            }
        }

        return $latest;
    }
}
