<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use stdClass;

/**
 * Typed readers for decoded JSON trees (stdClass objects).
 */
final class JsonFields
{
    /**
     * Read a string property from a decoded JSON object, or null.
     */
    public static function str(object $obj, string $key): ?string
    {
        $value = get_object_vars($obj)[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Read a boolean property from a decoded JSON object, or null.
     */
    public static function bool(object $obj, string $key): ?bool
    {
        $value = get_object_vars($obj)[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * Read a child object from a decoded JSON object, or null.
     */
    public static function child(object $parent, string $key): ?stdClass
    {
        $child = get_object_vars($parent)[$key] ?? null;

        return $child instanceof stdClass ? $child : null;
    }
}
