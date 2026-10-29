<?php

declare(strict_types=1);

namespace Waynestate\Creed\Bot;

use DateTimeImmutable;
use RuntimeException;

/**
 * The robot's alert channel: one open GitLab issue per alert key, labelled
 * auto-alert and titled [auto-alert] <key>. A key that is still present on
 * a later run gets a note on its existing issue instead of a new one; a key
 * that is no longer present closes its issue with a "Cleared on <date>."
 * note. People hear from the robot only through these issues.
 *
 * The keys come from the refresher's report:
 *
 *   held:<component>:<line>:<field>
 *   refused:<component>:<line>:<field>
 *   disagreement:<component>:<line>:<field>  while policy sides with neither source
 *   upcoming-eol:<component>:<line>          within 60 days of security_until
 */
final class AlertIssues
{
    /**
     * The label every alert issue carries.
     */
    public const LABEL = 'auto-alert';

    /**
     * Every alert issue title starts with this, then the key.
     */
    public const TITLE_PREFIX = '[auto-alert] ';

    /**
     * How many days before a listed line's security_until the upcoming-eol
     * key fires.
     */
    public const UPCOMING_EOL_DAYS = 60;

    /**
     * The decoded policy document as an associative array, cached after the
     * first read; empty when the file is missing or unreadable.
     *
     * @var array<string, mixed>|null
     */
    private ?array $policy = null;

    /**
     * @param string $policyPath path to policy/policy.json
     * @param string|null $today YYYY-MM-DD, the real today when null
     */
    public function __construct(
        private ?GitLabClient $client,
        private string $policyPath,
        private ?string $today = null,
    ) {}

    /**
     * What a dry run would do, without any transport call: one planned
     * issue per current key. Which of those already exist (and would get a
     * note instead) needs a live query, so a dry run assumes none do.
     *
     * @return list<array{key: string, action: string, title: string, body: string}>
     */
    public function plan(object $report): array
    {
        $plan = [];
        foreach ($this->currentKeys($report) as $key => $detail) {
            $plan[] = [
                'key' => $key,
                'action' => 'open',
                'title' => self::TITLE_PREFIX . $key,
                'body' => $this->issueBody($key, $detail),
            ];
        }

        return $plan;
    }

    /**
     * Bring the open auto-alert issues in line with the report: open an
     * issue for each new key, note each issue whose key is still present,
     * close each issue whose key is gone. Returns the exit code, the error
     * lines (safe to print as is) and the action lines.
     *
     * @return array{0: int, 1: list<string>, 2: list<string>}
     *
     * @throws RuntimeException when no client was injected
     */
    public function sync(object $report): array
    {
        if ($this->client === null) {
            throw new RuntimeException('alert issues need a GitLab client; a dry run must use plan()');
        }

        $keys = $this->currentKeys($report);
        $byTitle = [];
        foreach ($this->client->openIssues('') as $issue) {
            $title = $issue['title'] ?? null;
            $iid = $issue['iid'] ?? null;
            if (is_string($title) && is_int($iid)) {
                $byTitle[$title] = $iid;
            }
        }

        $errors = [];
        $actions = [];
        foreach ($keys as $key => $detail) {
            $title = self::TITLE_PREFIX . $key;
            try {
                if (isset($byTitle[$title])) {
                    $this->client->addNote($byTitle[$title], $this->noteBody($detail));
                    $actions[] = "noted {$title} (issue #{$byTitle[$title]})";
                } else {
                    $this->client->createIssue($title, $this->issueBody($key, $detail), [self::LABEL]);
                    $actions[] = "opened {$title}";
                }
            } catch (RuntimeException $e) {
                $errors[] = 'alert failed for ' . $key . ': ' . $e->getMessage();
            }
        }

        foreach ($byTitle as $title => $iid) {
            if (!str_starts_with($title, self::TITLE_PREFIX)) {
                continue;
            }
            $key = substr($title, strlen(self::TITLE_PREFIX));
            if (array_key_exists($key, $keys)) {
                continue;
            }
            try {
                $this->client->addNote($iid, 'Cleared on ' . $this->todayString() . '.');
                $this->client->closeIssue($iid);
                $actions[] = "closed {$title} (issue #{$iid})";
            } catch (RuntimeException $e) {
                $errors[] = 'alert failed for ' . $key . ': ' . $e->getMessage();
            }
        }

        return [$errors === [] ? 0 : 1, $errors, $actions];
    }

    /**
     * The current alert keys, each with the report lines that explain it.
     *
     * @return array<string, list<string>>
     */
    public function currentKeys(object $report): array
    {
        $keys = [];
        foreach ($this->items($report, 'held') as $item) {
            $key = $this->itemKey('held', $item);
            if ($key !== null) {
                $keys[$key] = [...($keys[$key] ?? []), $this->itemLine('held', $item)];
            }
        }
        foreach ($this->items($report, 'refused') as $item) {
            $key = $this->itemKey('refused', $item);
            if ($key !== null) {
                $keys[$key] = [...($keys[$key] ?? []), $this->itemLine('refused', $item)];
            }
        }
        foreach ($this->items($report, 'disagreements') as $item) {
            $key = $this->itemKey('disagreement', $item);
            if ($key === null || $this->policySidesWithSource($item)) {
                continue;
            }
            $keys[$key] = [...($keys[$key] ?? []), $this->disagreementLine($item)];
        }
        foreach ($this->upcomingEolKeys() as $key => $line) {
            $keys[$key] = [...($keys[$key] ?? []), $line];
        }

        return $keys;
    }

    /**
     * The description for a new issue.
     *
     * @param list<string> $detail
     */
    private function issueBody(string $key, array $detail): string
    {
        return implode("\n", [$key, '', ...$detail, '', 'First seen on ' . $this->todayString() . '. Raised by the CREED policy robot.']);
    }

    /**
     * The note for an issue whose key is still present.
     *
     * @param list<string> $detail
     */
    private function noteBody(array $detail): string
    {
        return implode("\n", ['Still present on ' . $this->todayString() . '.', '', ...$detail]);
    }

    /**
     * One held or refused item as "held php 8.3: security_until 2026-12-31
     * -> 2027-12-31 [loosen-fail]: reason", the refresher's own wording.
     */
    private function itemLine(string $verb, object $item): string
    {
        $old = $item->old ?? null;
        $new = $item->new ?? null;
        $summary = sprintf(
            '%s %s: %s %s -> %s',
            (string) ($item->component ?? '?'),
            (string) ($item->line ?? '?'),
            (string) ($item->field ?? '?'),
            $old !== null ? (string) $old : '(none)',
            $new !== null ? (string) $new : '(removed)',
        );
        $line = "{$verb} {$summary} [" . (string) ($item->class ?? '?') . ']';
        $reason = is_string($item->reason ?? null) && $item->reason !== '' ? ': ' . $item->reason : '';

        return $line . $reason;
    }

    /**
     * One disagreement as "node 26 active_until: a says X, b says Y; policy
     * currently says Z".
     */
    private function disagreementLine(object $item): string
    {
        $line = sprintf(
            '%s %s %s: %s says %s, %s says %s',
            (string) ($item->component ?? '?'),
            (string) ($item->line ?? '?'),
            (string) ($item->field ?? '?'),
            (string) ($item->primary_source ?? '?'),
            (string) ($item->primary_value ?? '?'),
            (string) ($item->secondary_source ?? '?'),
            (string) ($item->secondary_value ?? '?'),
        );
        $current = $this->policyValue(
            (string) ($item->component ?? ''),
            (string) ($item->line ?? ''),
            (string) ($item->field ?? ''),
        );

        return $line . '; policy currently says ' . ($current === null ? '(not listed)' : (string) $current);
    }

    /**
     * The held/refused/disagreement key for one report item, or null when
     * the item lacks a component, line or field.
     */
    private function itemKey(string $kind, object $item): ?string
    {
        $component = $item->component ?? null;
        $line = $item->line ?? null;
        $field = $item->field ?? null;
        if (!is_string($component) || $component === ''
            || !is_string($line) || $line === ''
            || !is_string($field) || $field === '') {
            return null;
        }

        return "{$kind}:{$component}:{$line}:{$field}";
    }

    /**
     * Whether policy.json already holds one of the two values a
     * disagreement reports. While it does, the robot stays quiet about it.
     */
    private function policySidesWithSource(object $item): bool
    {
        $current = $this->policyValue(
            (string) ($item->component ?? ''),
            (string) ($item->line ?? ''),
            (string) ($item->field ?? ''),
        );
        if ($current === null) {
            return false;
        }
        $primary = $item->primary_value ?? null;
        $secondary = $item->secondary_value ?? null;

        return $current === $primary || $current === $secondary;
    }

    /**
     * components.<component>.versions.<line>.<field> in policy.json, or
     * null when any part of the path is missing.
     */
    private function policyValue(string $component, string $line, string $field): mixed
    {
        $components = $this->policyDocument()['components'] ?? null;
        if (!is_array($components)) {
            return null;
        }
        $version = $components[$component] ?? null;
        if (!is_array($version)) {
            return null;
        }
        $versions = $version['versions'] ?? null;
        if (!is_array($versions)) {
            return null;
        }
        $entry = $versions[$line] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        return $entry[$field] ?? null;
    }

    /**
     * The upcoming-eol keys: every listed line whose security_until falls
     * within UPCOMING_EOL_DAYS ahead of today.
     *
     * @return array<string, string> key => explaining line
     */
    private function upcomingEolKeys(): array
    {
        $today = $this->todayDate();
        $components = $this->policyDocument()['components'] ?? null;
        if (!is_array($components)) {
            return [];
        }
        $keys = [];
        foreach ($components as $name => $component) {
            if (!is_array($component)) {
                continue;
            }
            $versions = $component['versions'] ?? null;
            if (!is_array($versions)) {
                continue;
            }
            foreach ($versions as $line => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $until = $entry['security_until'] ?? null;
                if (!is_string($until) || $until === '') {
                    continue;
                }
                $end = DateTimeImmutable::createFromFormat('!Y-m-d', $until);
                if ($end === false || $end < $today || $today->diff($end)->days > self::UPCOMING_EOL_DAYS) {
                    continue;
                }
                $days = $today->diff($end)->days;
                $when = $days === 0 ? 'is today' : "is {$days} day" . ($days === 1 ? '' : 's') . ' away';
                $keys["upcoming-eol:{$name}:{$line}"] = "{$name} {$line} security_until {$until} {$when}";
            }
        }

        return $keys;
    }

    /**
     * The decoded policy/policy.json as an associative array, cached, or an
     * empty array when the file is missing or unreadable.
     *
     * @return array<string, mixed>
     */
    private function policyDocument(): array
    {
        if ($this->policy !== null) {
            return $this->policy;
        }
        $raw = @file_get_contents($this->policyPath);
        if ($raw === false) {
            return $this->policy = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->policy = [];
        }

        return $this->policy = $decoded;
    }

    /**
     * The injected today (tests) or the real today, at midnight.
     */
    private function todayDate(): DateTimeImmutable
    {
        if ($this->today !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $this->today);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('today');
    }

    /**
     * Today as YYYY-MM-DD, for the note bodies.
     */
    private function todayString(): string
    {
        return $this->todayDate()->format('Y-m-d');
    }

    /**
     * The report items under one field, objects only.
     *
     * @return list<object>
     */
    private function items(object $report, string $field): array
    {
        $items = [];
        foreach ((array) ($report->{$field} ?? []) as $item) {
            if (is_object($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
