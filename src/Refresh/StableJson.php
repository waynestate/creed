<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use stdClass;
use RuntimeException;

/**
 * Encodes a decoded JSON tree (stdClass objects and arrays) in the policy's
 * stable format: 4-space indent, objects kept as objects (an empty object
 * stays "{}" and never becomes "[]"), unescaped slashes and unicode.
 */
final class StableJson
{
    /**
     * @throws RuntimeException when a value cannot be encoded
     */
    public static function encode(mixed $value): string
    {
        return self::encodeValue($value, 0);
    }

    private static function encodeValue(mixed $value, int $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $childIndent = str_repeat('    ', $depth + 1);

        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $encoded = json_encode($value);
            if ($encoded === false) {
                throw new RuntimeException('Could not encode a JSON number');
            }

            return $encoded;
        }
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (is_object($value)) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return '{}';
            }
            $parts = [];
            foreach ($vars as $key => $child) {
                $parts[] = $childIndent . self::encodeString((string) $key) . ': ' . self::encodeValue($child, $depth + 1);
            }

            return "{\n" . implode(",\n", $parts) . "\n" . $indent . '}';
        }
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $parts = [];
            foreach ($value as $child) {
                $parts[] = $childIndent . self::encodeValue($child, $depth + 1);
            }

            return "[\n" . implode(",\n", $parts) . "\n" . $indent . ']';
        }

        return 'null';
    }

    /**
     * @throws RuntimeException when the string cannot be encoded
     */
    private static function encodeString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode a JSON string');
        }

        return $encoded;
    }
}
