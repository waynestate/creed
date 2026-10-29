<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * Parses the php.net release states feed (the JSON behind
 * https://www.php.net/releases/) into branch => support facts.
 *
 * Feed shape: {"8": {"8.5": {"state": "stable",
 * "active_support_end": "2027-12-31T00:00:00+00:00",
 * "security_support_end": "2029-12-31T00:00:00+00:00"}}, ...}
 */
final class PhpStatesParser
{
    /**
     * @return array<string, array{state: ?string, active_until: ?string, security_until: ?string}>
     *         keyed by branch, for example "8.5"
     *
     * @throws RuntimeException when the feed is not the expected shape
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, false);
        if (!is_object($data)) {
            throw new RuntimeException('php.net states feed: expected a JSON object');
        }

        $branches = [];
        foreach (get_object_vars($data) as $generations) {
            if (!is_object($generations)) {
                continue;
            }
            foreach (get_object_vars($generations) as $branch => $info) {
                if (!is_object($info)) {
                    continue;
                }
                $branches[(string) $branch] = [
                    'state' => JsonFields::str($info, 'state'),
                    'active_until' => Dates::normalize(JsonFields::str($info, 'active_support_end')),
                    'security_until' => Dates::normalize(JsonFields::str($info, 'security_support_end')),
                ];
            }
        }

        if ($branches === []) {
            throw new RuntimeException('php.net states feed: no branches found');
        }

        return $branches;
    }
}
