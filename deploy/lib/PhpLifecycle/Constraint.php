<?php

declare(strict_types=1);

namespace Waynestate\Creed\Site\PhpLifecycle;

/**
 * A small reader for Composer version constraints, enough to answer one
 * question: does this `require.php` allow any patch of a PHP branch?
 *
 * It knows `^`, `~`, the comparison operators, wildcards, hyphen ranges, AND
 * (comma or space) and OR (`|` or `||`). Anything else is "unknown" (null),
 * never a guess. Like Creed Bratton's résumé, if we cannot read it, we do not
 * make it up.
 */
final class Constraint
{
    /** Patches tried per branch; no PHP branch has come close. */
    private const PATCHES = 120;

    /**
     * True when some X.Y.p satisfies the constraint, false when none does,
     * null when the constraint could not be read.
     */
    public static function allowsBranch(string $constraint, string $branch): ?bool
    {
        if (!preg_match('/^(\d+)\.(\d+)$/', $branch, $m)) {
            return null;
        }
        $groups = self::parse($constraint);
        if ($groups === null) {
            return null;
        }
        for ($p = 0; $p < self::PATCHES; $p++) {
            $version = $m[1] . '.' . $m[2] . '.' . $p;
            foreach ($groups as $terms) {
                if (self::groupAllows($terms, $version)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{0: string, 1: string}> $terms
     */
    private static function groupAllows(array $terms, string $version): bool
    {
        foreach ($terms as [$op, $bound]) {
            if (!version_compare($version, $bound, $op)) {
                return false;
            }
        }

        return true;
    }

    /**
     * OR groups of AND terms, each term an operator and a three-part version.
     *
     * @return list<list<array{0: string, 1: string}>>|null
     */
    private static function parse(string $constraint): ?array
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return null;
        }
        $groups = [];
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            $alternative = trim($alternative);
            if ($alternative === '') {
                return null;
            }
            $terms = [];
            if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $alternative, $h)) {
                $low = self::version($h[1]);
                $high = self::version($h[2]);
                if ($low === null || $high === null) {
                    return null;
                }
                $terms[] = ['>=', $low[0]];
                // A partial upper bound (8.5) means everything below the next step (8.6).
                $terms[] = $high[1] < 3 ? ['<', self::bump($high[0], $high[1] - 1)] : ['<=', $high[0]];
                $groups[] = $terms;
                continue;
            }
            $alternative = (string) preg_replace('/([<>=!^~]+)\s+/', '$1', $alternative);
            foreach (preg_split('/\s*,\s*|\s+/', $alternative) ?: [] as $term) {
                $parsed = self::term($term);
                if ($parsed === null) {
                    return null;
                }
                array_push($terms, ...$parsed);
            }
            $groups[] = $terms;
        }

        return $groups === [] ? null : $groups;
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private static function term(string $term): ?array
    {
        $term = (string) preg_replace('/@\w+$/', '', $term);
        if ($term === '*' || $term === '') {
            return [['>=', '0.0.0']];
        }
        if (!preg_match('/^(\^|~|>=|<=|>|<|==|=|!=)?v?(\d+(?:\.(?:\d+|\*))*)(?:-[\w.]+)?$/', $term, $m)) {
            return null;
        }
        $op = $m[1];
        $raw = $m[2];
        if (str_contains($raw, '*')) {
            if ($op !== '' && $op !== '=' && $op !== '==') {
                return null;
            }
            $fixed = array_values(array_filter(explode('.', $raw), static fn(string $p): bool => $p !== '*'));
            if ($fixed === []) {
                return [['>=', '0.0.0']];
            }
            $low = self::pad($fixed);

            return [['>=', $low], ['<', self::bump($low, count($fixed) - 1)]];
        }
        $v = self::version($raw);
        if ($v === null) {
            return null;
        }
        [$full, $parts] = $v;
        $nums = array_map('intval', explode('.', $full));

        return match ($op) {
            '^' => [['>=', $full], ['<', match (true) {
                $nums[0] > 0 => self::bump($full, 0),
                $nums[1] > 0 || $parts < 3 => self::bump($full, 1),
                default => self::bump($full, 2),
            }]],
            '~' => [['>=', $full], ['<', self::bump($full, max(0, $parts - 2))]],
            '', '=', '==' => $parts < 3 && $op === ''
                ? [['>=', $full], ['<', self::bump($full, $parts - 1)]]
                : [['==', $full]],
            '!=' => [['!=', $full]],
            default => [[$op, $full]],
        };
    }

    /**
     * A version padded to three parts, and how many parts it was written with.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function version(string $raw): ?array
    {
        $raw = ltrim($raw, 'v');
        if (!preg_match('/^\d+(\.\d+){0,3}$/', $raw)) {
            return null;
        }
        $parts = explode('.', $raw);

        return [self::pad($parts), min(3, count($parts))];
    }

    /**
     * @param list<string> $parts
     */
    private static function pad(array $parts): string
    {
        $parts = array_slice(array_map('intval', $parts), 0, 3);
        while (count($parts) < 3) {
            $parts[] = 0;
        }

        return implode('.', $parts);
    }

    /** The next step at one position: bump(8.2.0, 0) is 9.0.0, bump(8.2.0, 1) is 8.3.0. */
    private static function bump(string $version, int $position): string
    {
        $parts = array_map('intval', explode('.', $version));
        $position = max(0, min(2, $position));
        $parts[$position]++;
        for ($i = $position + 1; $i < 3; $i++) {
            $parts[$i] = 0;
        }

        return implode('.', $parts);
    }
}
