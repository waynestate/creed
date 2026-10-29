<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * Parses an npm dist-tags document (for example
 * https://registry.npmjs.org/@inertiajs/vue3/latest is one tag; the whole
 * tag set comes from the registry's /-/package/<name>/dist-tags endpoint)
 * into tag => version.
 *
 * Feed shape: {"latest": "3.9.1", "legacy": "2.3.29", ...}
 */
final class NpmDistTagsParser
{
    /**
     * @return array<int|string, string>
     *
     * @throws RuntimeException when the feed is not the expected shape or
     *                          carries no "latest" tag
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, false);
        if (!is_object($data)) {
            throw new RuntimeException('npm dist-tags feed: expected a JSON object');
        }

        $tags = [];
        foreach (get_object_vars($data) as $tag => $version) {
            if (is_string($version) && $version !== '') {
                $tags[(string) $tag] = $version;
            }
        }

        if (!isset($tags['latest'])) {
            throw new RuntimeException('npm dist-tags feed: missing "latest" tag');
        }

        return $tags;
    }
}
