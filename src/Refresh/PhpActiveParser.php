<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * Parses the php.net active releases feed (https://www.php.net/releases/active.php)
 * into branch => latest version.
 *
 * Feed shape: {"8": {"8.2": {"version": "8.2.35"}, ...}, ...}
 */
final class PhpActiveParser
{
    /**
     * @return array<string, string> keyed by branch, for example "8.2"
     *
     * @throws RuntimeException when the feed is not the expected shape
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, false);
        if (!is_object($data)) {
            throw new RuntimeException('php.net feed: expected a JSON object');
        }

        $latest = [];
        foreach (get_object_vars($data) as $generations) {
            if (!is_object($generations)) {
                continue;
            }
            foreach (get_object_vars($generations) as $branch => $info) {
                if (!is_object($info)) {
                    continue;
                }
                $version = JsonFields::str($info, 'version');
                if ($version !== null && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1) {
                    $latest[(string) $branch] = $version;
                }
            }
        }

        if ($latest === []) {
            throw new RuntimeException('php.net feed: no versions found');
        }

        return $latest;
    }
}
