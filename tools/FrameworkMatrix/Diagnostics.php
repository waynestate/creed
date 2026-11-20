<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\FrameworkMatrix;

/**
 * Reads the output of one framework matrix cell (Composer, PHPUnit, the PHP
 * built-in server and the page it served) for what PHP said along the way:
 * deprecations, warnings, notices and fatals, wherever a framework's own
 * error handler moved them to.
 *
 * This file runs inside the cell's container on every PHP line from 8.0 up,
 * so it keeps to PHP 8.0 syntax: no enums, no readonly, no first-class
 * callables. Like Creed Bratton's alibi, it has to hold up in any decade.
 */
final class Diagnostics
{
    /** PHP's own message types, as the CLI and the HTML error display print them. */
    private const NATIVE = 'Deprecated|User Deprecated|Warning|User Warning|Core Warning|Compile Warning|Notice|User Notice|Strict Standards|Fatal error|Parse error|Recoverable fatal error';

    /**
     * Every diagnostic in one step's output, in order.
     *
     * Kinds: deprecation, warning (notices count as warnings), fatal. Source:
     * "tool" when Composer itself said it (its phar, not the framework), else "app".
     *
     * @return list<array{kind: string, message: string, file: string, line: int, source: string}>
     */
    public static function scan(string $text): array
    {
        $text = self::plain($text);
        $found = [];

        // PHPUnit 10 and later collect issues and list them in sections at the end.
        foreach (self::phpunitIssues($text) as $issue) {
            $found[] = $issue;
        }

        $patterns = [
            // PHP itself: "Deprecated: msg in /f.php on line 12" (CLI, HTML display, "PHP " log prefix).
            '/(?:^|[\s>])(?:PHP\s+)?(' . self::NATIVE . '):\s+(.+?) in (\S+?) on line (\d+)/m',
            // Composer's own error handler.
            '/(Deprecation Notice): (.+?) in (\S+?):(\d+)\s*$/m',
            // Laravel's deprecations channel (LOG_DEPRECATIONS_CHANNEL=stderr): "local.WARNING: msg in /f.php on line 12".
            '/\]\s+\w+\.(WARNING): (.+?) in (\S+?) on line (\d+)/m',
            // CakePHP's error renderers: "Deprecated (8192): msg [/f.php, line 12]" or "... in [/f.php, line 12]".
            '/(Deprecated|Warning|Notice|Strict)\s*\(\d+\)\s*:\s*(.+?)\s*(?:in\s*)?\[(\S+?),\s*line\s*(\d+)\]/m',
        ];
        foreach ($patterns as $index => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $m) {
                $label = $m[1];
                $kind = match (true) {
                    $index === 2 => 'deprecation',
                    stripos($label, 'Deprecat') !== false => 'deprecation',
                    stripos($label, 'error') !== false => 'fatal',
                    default => 'warning',
                };
                $found[] = self::entry($kind, $m[2], $m[3], (int) $m[4]);
            }
        }

        return $found;
    }

    /**
     * The PHPUnit summary: counts by name ("tests", "assertions", "failures",
     * "errors", "deprecations", "warnings", "notices", "skipped", "risky",
     * "incomplete", "phpunit deprecations"), or null when none was printed.
     *
     * @return array<string, int>|null
     */
    public static function phpunitSummary(string $text): ?array
    {
        $text = self::plain($text);
        if (preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $text, $m) === 1) {
            return ['tests' => (int) $m[1], 'assertions' => (int) $m[2]];
        }
        if (preg_match_all('/^Tests: (\d+), Assertions: (\d+)(.*)$/m', $text, $all, PREG_SET_ORDER) > 0) {
            $last = $all[count($all) - 1];
            $summary = ['tests' => (int) $last[1], 'assertions' => (int) $last[2]];
            if (preg_match_all('/,\s*([A-Za-z][A-Za-z ]*?):\s*(\d+)/', $last[3], $pairs, PREG_SET_ORDER) > 0) {
                foreach ($pairs as $pair) {
                    $summary[strtolower($pair[1])] = (int) $pair[2];
                }
            }

            return $summary;
        }
        if (str_contains($text, 'No tests executed!')) {
            return ['tests' => 0, 'assertions' => 0];
        }

        return null;
    }

    /** True when Composer said no because of the PHP version (the platform), not for any other reason. */
    public static function isPlatformRefusal(string $text): bool
    {
        $text = self::plain($text);
        if (preg_match('/installable using your PHP version/i', $text) === 1) {
            return true;
        }
        if (!str_contains($text, 'Your requirements could not be resolved')) {
            return false;
        }

        return preg_match('/requires php [^\n]*-> your php version|your php version \([^)]*\) does not satisfy/i', $text) === 1;
    }

    /** The first line that says what went wrong, or null. Refusals get the line naming the PHP requirement. */
    public static function firstError(string $text): ?string
    {
        $lines = preg_split('/\R/', self::plain($text)) ?: [];
        // Composer lists every package that ruled the platform out; the last one is the deepest cause
        // (CakePHP 4.6 itself allows PHP 8.5, the laminas-diactoros it needs does not).
        $platform = null;
        foreach ($lines as $line) {
            if (preg_match('/installable using your PHP version|requires? php .*your php version/i', $line) === 1) {
                $platform = trim($line);
            }
        }
        if ($platform !== null) {
            return self::clip($platform);
        }
        $patterns = [
            '/^\s*- .*\b(requires?|conflicts)\b/',
            '/(Fatal error|Uncaught|Parse error)/',
            '/\b(error|exception|failed|could not|cannot)\b/i',
        ];
        foreach ($patterns as $pattern) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && preg_match($pattern, $line) === 1) {
                    return self::clip($line);
                }
            }
        }
        // Nothing said "error": the first line that is not the command itself or a banner.
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '$ ') && preg_match('/^PHPUnit [\d.]+ by /', $line) !== 1) {
                return self::clip($line);
            }
        }

        return null;
    }

    /** The skeleton version create-project installed: "Installing laravel/laravel (v13.11.0)". */
    public static function skeletonVersion(string $text, string $skeleton): ?string
    {
        if (preg_match('/Installing ' . preg_quote($skeleton, '/') . ' \(([^)]+)\)/', self::plain($text), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Distinct messages, first seen first: one per kind, message and place.
     *
     * @param list<array{kind: string, message: string, file: string, line: int, source: string, step?: string}> $diagnostics
     *
     * @return list<array{kind: string, message: string, file: string, line: int, source: string, step?: string}>
     */
    public static function distinct(array $diagnostics): array
    {
        $seen = [];
        $out = [];
        foreach ($diagnostics as $d) {
            $key = $d['kind'] . '|' . $d['message'] . '|' . $d['file'] . '|' . $d['line'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * PHPUnit 10+ issue sections: "1 test triggered 2 deprecations:" then
     * "1) /f.php:12" and the message on the next line. Sections about
     * PHPUnit itself (its own deprecations) are not PHP diagnostics.
     *
     * @return list<array{kind: string, message: string, file: string, line: int, source: string}>
     */
    private static function phpunitIssues(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $found = [];
        $kind = null;
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            if (preg_match('/^(?:\d+ tests? triggered \d+|There (?:was|were) \d+)\s+(PHP |PHPUnit |test runner )?(deprecations?|warnings?|notices?|errors?)(?: \(\d+ unique\))?:$/i', $line, $h) === 1) {
                $qualifier = strtolower(trim($h[1]));
                $what = strtolower($h[2]);
                $kind = null;
                if ($qualifier !== 'phpunit' && $qualifier !== 'test runner' && !str_starts_with($what, 'error')) {
                    $kind = str_starts_with($what, 'deprecation') ? 'deprecation' : 'warning';
                }
                continue;
            }
            if ($kind === null) {
                continue;
            }
            if (preg_match('/^\d+\) (\S+):(\d+)$/', $line, $m) === 1 && isset($lines[$i + 1]) && trim($lines[$i + 1]) !== '') {
                $found[] = self::entry($kind, trim($lines[$i + 1]), $m[1], (int) $m[2]);
                $i++;
                continue;
            }
            if (preg_match('/^(OK|FAILURES|ERRORS|Tests: \d+)/', $line) === 1) {
                $kind = null;
            }
        }

        return $found;
    }

    /**
     * @return array{kind: string, message: string, file: string, line: int, source: string}
     */
    private static function entry(string $kind, string $message, string $file, int $line): array
    {
        $source = (str_contains($file, '/bin/composer') || str_starts_with($file, 'phar://')) ? 'tool' : 'app';

        return ['kind' => $kind, 'message' => self::clip(trim($message)), 'file' => self::relative($file), 'line' => $line, 'source' => $source];
    }

    /** Paths inside the created app, relative to it: the results never name a container's scratch folder. */
    private static function relative(string $file): string
    {
        return (string) preg_replace('#^/tmp/app/#', '', $file);
    }

    private static function clip(string $line): string
    {
        $line = (string) preg_replace('#/tmp/app/#', '', $line);

        return strlen($line) > 300 ? substr($line, 0, 297) . '...' : $line;
    }

    /** Output as text: no ANSI colors, no HTML tags, entities decoded. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text);
        if (preg_match('/<(b|br|html|div|span|pre)\b/i', $text) === 1) {
            $text = html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>/i', "\n", $text)), ENT_QUOTES | ENT_HTML5);
        }

        return $text;
    }
}
