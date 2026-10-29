<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * Small helpers for plain X.Y[.Z] version strings.
 */
final class Versions
{
    /**
     * The leading segment of a version: "8.2.35" gives "8", "v26.13.0" gives "26".
     */
    public static function major(string $version): string
    {
        $plain = self::stripV($version);

        return explode('.', $plain)[0];
    }

    /**
     * Strip a leading "v" from a version string.
     */
    public static function stripV(string $version): string
    {
        return preg_replace('/^v/', '', $version) ?? $version;
    }

    /**
     * Whether the string is a plain stable X.Y.Z version.
     */
    public static function isStableXyz(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', self::stripV($version)) === 1;
    }

    /**
     * Compare two version strings like version_compare does: -1, 0 or 1.
     */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::stripV($a), self::stripV($b));
    }

    /**
     * The highest of a list of versions, or null when the list is empty.
     *
     * @param list<string> $versions
     */
    public static function highest(array $versions): ?string
    {
        $best = null;
        foreach ($versions as $version) {
            if ($best === null || self::compare($version, $best) > 0) {
                $best = $version;
            }
        }

        return $best;
    }

    /**
     * Whether a candidate sorts above every listed line. An empty list
     * counts as "above all".
     *
     * @param array<int|string, mixed> $listed
     */
    public static function aboveAll(string $candidate, array $listed): bool
    {
        foreach (array_keys($listed) as $line) {
            if (self::compare($candidate, (string) $line) <= 0) {
                return false;
            }
        }

        return true;
    }
}
