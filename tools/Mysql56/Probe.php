<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tools\Mysql56;

/**
 * One claim the databases chapter makes about MySQL 5.6, written as SQL a
 * Laravel 13 app would send, and the answer 5.6.51 is expected to give.
 *
 * `expect` is one of:
 *  - `ok`: the statement runs.
 *  - `error:<code>`: the server refuses it with that MySQL error code.
 *  - `warning:<code>`: it runs, and SHOW WARNINGS carries that code.
 *  - `value:<text>`: it runs, and the first column of its first row is that text.
 *
 * `setup` runs first and must succeed; only `sql` is judged. Tables are named
 * `creed_probe_*`, so the runner can sweep them before and after.
 *
 * Creed Bratton swears he played a club in 1966 that only served drinks from
 * 1956. This class checks the bar's menu instead of taking his word for it.
 */
final class Probe
{
    /**
     * @param list<string> $setup statements that prepare the probe and must succeed
     */
    public function __construct(
        public readonly string $id,
        public readonly string $claim,
        public readonly array $setup,
        public readonly string $sql,
        public readonly string $expect,
    ) {}

    /** True when `expect` is one of the four forms above. */
    public function expectIsWellFormed(): bool
    {
        return preg_match('/^(?:ok|error:\d{4}|warning:\d{4}|value:.*)$/', $this->expect) === 1;
    }

    /**
     * Compares what the server did with what the chapter says it does.
     *
     * @param int|null $error the MySQL error code the statement raised, or null when it ran
     * @param list<int> $warnings the codes SHOW WARNINGS listed after it ran
     * @param string|null $value the first column of the first row, when it returned one
     * @return string|null null when the server agreed, else why it did not
     */
    public function verdict(?int $error, array $warnings, ?string $value): ?string
    {
        $kind = strstr($this->expect, ':', true);
        $want = $kind === false ? '' : substr($this->expect, strlen($kind) + 1);
        $kind = $kind === false ? $this->expect : $kind;

        if ($kind === 'error') {
            if ($error === null) {
                return "expected error {$want}, but the statement ran";
            }

            return (string) $error === $want ? null : "expected error {$want}, got error {$error}";
        }

        if ($error !== null) {
            return "expected {$this->expect}, got error {$error}";
        }

        return match ($kind) {
            'ok' => null,
            'warning' => in_array((int) $want, $warnings, true)
                ? null
                : "expected warning {$want}, got " . ($warnings === [] ? 'none' : implode(', ', $warnings)),
            'value' => $value === $want ? null : "expected value {$want}, got " . ($value ?? 'no row'),
            default => "unknown expectation {$this->expect}",
        };
    }
}
