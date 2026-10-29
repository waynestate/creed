<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use stdClass;

/**
 * The provenance ledger behind policy/sources.json.
 *
 * For every value it proposes, CREED remembers which source said it, the
 * value, the first day that source said exactly that and the last day it
 * was seen. A held change may be released automatically only when the same
 * value has stood since at least MIN_STABLE_DAYS ago: one day of upstream
 * noise is not a decision. A different value starts the clock again.
 *
 * Shape: {"schema_version": 1, "updated_on": "YYYY-MM-DD", "observations":
 * {component: {line: {field: {source: {"value", "first_seen",
 * "last_seen"}}}}}}
 *
 * Only a new or changed value counts as a material change. Moving
 * last_seen forward is bookkeeping and must not make a run "write", or the
 * robot would open a merge request every day.
 */
final class SourceLedger
{
    public const SCHEMA_VERSION = 1;

    public const MIN_STABLE_DAYS = 14;

    /**
     * @var array<string, array<string, array<string, array<string, array{value: string, first_seen: string, last_seen: string}>>>>
     */
    private array $observations = [];

    private ?string $updatedOn = null;

    private bool $materialChange = false;

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Decode an existing sources.json document. Returns null when the
     * document cannot be used, so the caller can start fresh and report the
     * problem instead of guessing.
     */
    public static function decode(?string $raw): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, false);
        if (!is_object($decoded)) {
            return null;
        }
        if ((get_object_vars($decoded)['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        $ledger = new self();
        $ledger->updatedOn = JsonFields::str($decoded, 'updated_on');

        $observations = JsonFields::child($decoded, 'observations');
        if ($observations === null) {
            return $ledger;
        }
        foreach (get_object_vars($observations) as $component => $lines) {
            if (!is_object($lines)) {
                return null;
            }
            foreach (get_object_vars($lines) as $line => $fields) {
                if (!is_object($fields)) {
                    return null;
                }
                foreach (get_object_vars($fields) as $field => $bySource) {
                    if (!is_object($bySource)) {
                        return null;
                    }
                    foreach (get_object_vars($bySource) as $source => $entry) {
                        if (!is_object($entry)) {
                            return null;
                        }
                        $value = JsonFields::str($entry, 'value');
                        $first = JsonFields::str($entry, 'first_seen');
                        $last = JsonFields::str($entry, 'last_seen');
                        if ($value === null || $first === null || $last === null) {
                            return null;
                        }
                        $ledger->observations[(string) $component][(string) $line][(string) $field][(string) $source] = [
                            'value' => $value,
                            'first_seen' => $first,
                            'last_seen' => $last,
                        ];
                    }
                }
            }
        }

        return $ledger;
    }

    /**
     * Remember what a source said today.
     */
    public function record(string $component, string $line, string $field, string $source, string $value, string $today): void
    {
        $existing = $this->observations[$component][$line][$field][$source] ?? null;
        if ($existing !== null && $existing['value'] === $value) {
            if ($today > $existing['last_seen']) {
                $this->observations[$component][$line][$field][$source]['last_seen'] = $today;
                $this->updatedOn = $today;
            }

            return;
        }
        $this->observations[$component][$line][$field][$source] = [
            'value' => $value,
            'first_seen' => $today,
            'last_seen' => $today,
        ];
        $this->updatedOn = $today;
        $this->materialChange = true;
    }

    /**
     * Whether a value is safe to trust on one source: the source has said
     * exactly this value since at least $minDays ago and said it again
     * today.
     */
    public function stableFor(string $component, string $line, string $field, string $source, string $value, string $today, int $minDays = self::MIN_STABLE_DAYS): bool
    {
        $entry = $this->observations[$component][$line][$field][$source] ?? null;
        if ($entry === null || $entry['value'] !== $value || $entry['last_seen'] !== $today) {
            return false;
        }

        return self::daySpan($entry['first_seen'], $today) >= $minDays;
    }

    /**
     * Whether this run saw a value for the first time or saw it change.
     */
    public function hasMaterialChange(): bool
    {
        return $this->materialChange;
    }

    /**
     * Whole days between two "YYYY-MM-DD" dates, or 0 when either is not a
     * date.
     */
    public static function daySpan(string $from, string $to): int
    {
        $a = strtotime($from . 'T00:00:00Z');
        $b = strtotime($to . 'T00:00:00Z');
        if ($a === false || $b === false) {
            return 0;
        }

        return intdiv($b - $a, 86400);
    }

    public function hasObservations(): bool
    {
        return $this->observations !== [];
    }

    /**
     * Encode the ledger with deterministic key order, ready for StableJson.
     */
    public function toObject(): stdClass
    {
        $observations = new stdClass();
        foreach (self::sortedKeys(array_keys($this->observations)) as $component) {
            $lines = new stdClass();
            foreach (self::sortedKeys(array_keys($this->observations[$component])) as $line) {
                $fields = new stdClass();
                foreach (self::sortedKeys(array_keys($this->observations[$component][$line])) as $field) {
                    $bySource = new stdClass();
                    foreach (self::sortedKeys(array_keys($this->observations[$component][$line][$field])) as $source) {
                        $entry = $this->observations[$component][$line][$field][$source];
                        $item = new stdClass();
                        $item->value = $entry['value'];
                        $item->first_seen = $entry['first_seen'];
                        $item->last_seen = $entry['last_seen'];
                        $bySource->$source = $item;
                    }
                    $fields->$field = $bySource;
                }
                $lines->$line = $fields;
            }
            $observations->$component = $lines;
        }

        $root = new stdClass();
        $root->schema_version = self::SCHEMA_VERSION;
        if ($this->updatedOn !== null) {
            $root->updated_on = $this->updatedOn;
        }
        $root->observations = $observations;

        return $root;
    }

    /**
     * Natural-order key sort, so "9" comes before "10".
     *
     * @param list<int|string> $keys
     *
     * @return list<string>
     */
    private static function sortedKeys(array $keys): array
    {
        $strings = array_map(static fn(int|string $key): string => (string) $key, $keys);
        usort($strings, static fn(string $a, string $b): int => strnatcasecmp($a, $b));

        return $strings;
    }
}
