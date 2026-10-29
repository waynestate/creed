<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * Date handling for the refresh tool. All policy dates are "YYYY-MM-DD";
 * feeds may carry full ISO 8601 datetimes, which are normalised down.
 */
final class Dates
{
    /**
     * Normalise a feed date to "YYYY-MM-DD", or pass a policy date through.
     * Returns null for null or an unrecognised value.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', $value, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
