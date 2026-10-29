<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use RuntimeException;

/**
 * Parses the Node.js release schedule (schedule.json from the nodejs/Release
 * repository) into major => support dates.
 *
 * Feed shape: {"v20": {"start": "...", "lts": "...", "maintenance":
 * "2024-10-22", "end": "2026-04-30", "codename": "Iron"}, ...}
 *
 * Only maintenance and end are kept: maintenance is the last day of active
 * support, end the last day of any support.
 */
final class NodeScheduleParser
{
    /**
     * @return array<string, array{maintenance: ?string, end: ?string}>
     *         keyed by major without the "v", for example "20"
     *
     * @throws RuntimeException when the feed is not the expected shape
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, false);
        if (!is_object($data)) {
            throw new RuntimeException('nodejs schedule feed: expected a JSON object');
        }

        $schedule = [];
        foreach (get_object_vars($data) as $key => $info) {
            if (!is_object($info)) {
                continue;
            }
            $major = ltrim((string) $key, 'v');
            if ($major === '') {
                continue;
            }
            $schedule[$major] = [
                'maintenance' => Dates::normalize(JsonFields::str($info, 'maintenance')),
                'end' => Dates::normalize(JsonFields::str($info, 'end')),
            ];
        }

        if ($schedule === []) {
            throw new RuntimeException('nodejs schedule feed: no releases found');
        }

        return $schedule;
    }
}
