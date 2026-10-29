<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * Parses the nodejs.org dist index (https://nodejs.org/dist/index.json) into
 * major => latest stable version.
 *
 * Feed shape: [{"version": "v26.13.0", ...}, ...]
 */
final class NodeDistParser
{
    /**
     * @return array<int|string, string> keyed by major, for example "26"
     *
     * @throws RuntimeException when the feed is not the expected shape
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, false);
        if (!is_array($data)) {
            throw new RuntimeException('nodejs.org feed: expected a JSON array');
        }

        /** @var array<string, list<string>> $byMajor */
        $byMajor = [];
        foreach ($data as $entry) {
            if (!is_object($entry)) {
                continue;
            }
            $version = JsonFields::str($entry, 'version');
            if ($version === null) {
                continue;
            }
            $plain = Versions::stripV($version);
            if (!Versions::isStableXyz($plain)) {
                continue; // not a stable X.Y.Z release
            }
            $byMajor[Versions::major($plain)][] = $plain;
        }

        $latest = [];
        foreach ($byMajor as $major => $candidates) {
            $best = Versions::highest($candidates);
            if ($best !== null) {
                $latest[$major] = $best;
            }
        }

        if ($latest === []) {
            throw new RuntimeException('nodejs.org feed: no versions found');
        }

        return $latest;
    }
}
