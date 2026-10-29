<?php

declare(strict_types=1);

namespace Waynestate\Creed\Bot;

use stdClass;

/**
 * The CREED policy robot: the decision core that keeps policy/policy.json
 * current with no human in the loop.
 *
 * One run works like this:
 *
 *   1. Run bin/refresh-policy.php as a subprocess through the CommandRunner.
 *   2. Read the run report the refresher wrote with --report.
 *   3. Decide the outcome from the refresher's exit code and the report:
 *
 *        exit 0  nothing worth committing was written: the robot does nothing
 *        exit 3  changes were written: the robot plans the commit (the data
 *                allowlist files) and builds the merge request title and body
 *        exit 4  a change was held or refused: nothing is committed, and the
 *                report items are the record of why
 *        exit 1  a hard failure: the robot stops with exit 1
 *
 * With --dry-run the refresher writes nothing and always exits 0; the robot
 * then prints the decided outcome, the planned files, the merge request
 * title and body, and touches nothing.
 *
 * Committing, pushing and opening the GitLab merge request are later steps;
 * this core only decides and reports.
 */
final readonly class PolicyBot
{
    /**
     * The merge request title, exactly.
     */
    public const MR_TITLE = 'policy: refresh from upstream sources';

    /**
     * The only paths a policy refresh may change; a commit stages exactly
     * these, nothing else.
     *
     * @var list<string>
     */
    public const DATA_ALLOWLIST = [
        'policy/policy.json',
        'policy/sources.json',
    ];

    public function __construct(
        private string $repoRoot,
        private CommandRunner $runner,
        private bool $dryRun = false,
    ) {}

    /**
     * One scheduled run.
     *
     * @param string|null $offlineDir feed directory for the refresher, or null to fetch
     *
     * @return int 0 on every handled outcome, 1 on a hard failure
     */
    public function run(?string $offlineDir = null): int
    {
        $reportPath = tempnam(sys_get_temp_dir(), 'creed-policy-report-');
        if ($reportPath === false) {
            echo "the policy robot could not create a report file\n";

            return 1;
        }

        try {
            $argv = [PHP_BINARY, $this->repoRoot . '/bin/refresh-policy.php', '--report', $reportPath];
            if ($offlineDir !== null) {
                $argv[] = '--offline';
                $argv[] = $offlineDir;
            }
            if ($this->dryRun) {
                $argv[] = '--dry-run';
            }

            try {
                $result = $this->runner->run($argv);
            } catch (CommandException $e) {
                echo 'the refresher could not start: ' . $e->getMessage() . "\n";

                return 1;
            }

            if ($result['stdout'] !== '') {
                echo $result['stdout'];
            }
            if ($result['stderr'] !== '') {
                fwrite(STDERR, $result['stderr']);
            }

            if ($result['status'] === 1) {
                echo "the refresher failed (exit 1); the robot stops here\n";

                return 1;
            }

            $report = $this->readReport($reportPath);

            return $this->dryRun ? $this->dryRunOutcome($report) : $this->decide($result['status'], $report);
        } finally {
            if (is_file($reportPath)) {
                @unlink($reportPath);
            }
        }
    }

    /**
     * The merge request title, exactly.
     */
    public function mrTitle(): string
    {
        return self::MR_TITLE;
    }

    /**
     * The merge request body: every change with its class, plus the held,
     * refused, disagreement and source-error sections, all from the report.
     */
    public function mrBody(object $report): string
    {
        $date = is_string($report->generated_on ?? null) ? $report->generated_on : '';
        $lines = [$date === '' ? 'Auto-refresh from the upstream sources.' : "Auto-refresh from the upstream sources on {$date}."];

        $sections = [];
        foreach ([
            'Changes' => 'changes',
            'Held' => 'held',
            'Refused' => 'refused',
        ] as $heading => $field) {
            $section = [];
            foreach ($this->items($report, $field) as $item) {
                $line = '- ' . self::summaryLine($item) . ' [' . (string) ($item->class ?? '?') . ']';
                $reason = is_string($item->reason ?? null) && $item->reason !== '' ? ': ' . $item->reason : '';
                $section[] = $line . $reason;
            }
            if ($section !== []) {
                $sections[] = [$heading . ':', ...$section];
            }
        }

        $disagreements = [];
        foreach ($this->items($report, 'disagreements') as $item) {
            $disagreements[] = sprintf(
                '- %s %s %s: %s says %s, %s says %s',
                (string) ($item->component ?? '?'),
                (string) ($item->line ?? '?'),
                (string) ($item->field ?? '?'),
                (string) ($item->primary_source ?? '?'),
                (string) ($item->primary_value ?? '?'),
                (string) ($item->secondary_source ?? '?'),
                (string) ($item->secondary_value ?? '?'),
            );
        }
        if ($disagreements !== []) {
            $sections[] = ['Disagreements:', ...$disagreements];
        }

        $errors = [];
        foreach ($this->strings($report, 'source_errors') as $error) {
            $errors[] = "- {$error}";
        }
        if ($errors !== []) {
            $sections[] = ['Sources that failed this run:', ...$errors];
        }

        if ($sections !== []) {
            $lines[] = '';
            foreach ($sections as $section) {
                foreach ($section as $line) {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The decided outcome for a real (non dry-run) run.
     */
    private function decide(int $exit, object $report): int
    {
        return match ($exit) {
            0 => $this->nothingToDo(),
            3 => $this->commitPlan($report),
            4 => $this->heldOrRefused($report),
            default => $this->unexpectedExit($exit),
        };
    }

    private function nothingToDo(): int
    {
        echo "nothing to do\n";

        return 0;
    }

    /**
     * Changes were written: the commit list and the merge request.
     */
    private function commitPlan(object $report): int
    {
        echo "changes written; the commit plan:\n";
        foreach ($this->plannedFiles() as $file) {
            echo "  {$file}\n";
        }

        $this->printRecords($report);
        $this->printMergeRequest($report);

        return 0;
    }

    /**
     * A change was held or refused: nothing is committed, and the report
     * items are the record of why.
     */
    private function heldOrRefused(object $report): int
    {
        echo "the refresher held or refused changes; nothing is committed\n";
        $this->printRecords($report);

        return 0;
    }

    private function unexpectedExit(int $exit): int
    {
        echo "the refresher exited with an unknown code ({$exit}); treating it as a failure\n";

        return 1;
    }

    /**
     * What a dry run would have done. A dry-run refresher always exits 0
     * and writes nothing, so the report alone decides the outcome.
     */
    private function dryRunOutcome(object $report): int
    {
        $changes = $this->items($report, 'changes');
        $held = $this->items($report, 'held');
        $refused = $this->items($report, 'refused');
        $disagreements = $this->items($report, 'disagreements');
        $errors = $this->strings($report, 'source_errors');

        if ($changes === [] && $held === [] && $refused === [] && $disagreements === [] && $errors === []) {
            echo "dry run: nothing to do\n";

            return 0;
        }

        if ($changes !== []) {
            echo "dry run: would commit these files:\n";
            foreach ($this->plannedFiles() as $file) {
                echo "  {$file}\n";
            }
        } else {
            echo 'dry run: nothing to commit (' . $this->countPhrases($held, $refused, $disagreements, $errors) . ")\n";
        }

        $this->printRecords($report);
        $this->printMergeRequest($report);

        return 0;
    }

    /**
     * The held and refused items, one line each, in the refresher's own
     * wording.
     */
    private function printRecords(object $report): void
    {
        foreach ($this->items($report, 'held') as $item) {
            echo self::recordLine('held', $item) . "\n";
        }
        foreach ($this->items($report, 'refused') as $item) {
            echo self::recordLine('refused', $item) . "\n";
        }
    }

    /**
     * The merge request title and body.
     */
    private function printMergeRequest(object $report): void
    {
        echo 'merge request title: ' . $this->mrTitle() . "\n";
        echo "merge request body:\n";
        echo $this->mrBody($report) . "\n";
    }

    /**
     * The data allowlist files that exist in the repository: what a commit
     * would stage.
     *
     * @return list<string>
     */
    private function plannedFiles(): array
    {
        $files = [];
        foreach (self::DATA_ALLOWLIST as $path) {
            if (is_file($this->repoRoot . '/' . $path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * "(1 refused, 1 disagreement)" style counts for the non-zero parts.
     *
     * @param list<object> $held
     * @param list<object> $refused
     * @param list<object> $disagreements
     * @param list<string> $errors
     */
    private function countPhrases(array $held, array $refused, array $disagreements, array $errors): string
    {
        $phrases = [];
        foreach ([
            [count($held), 'held', 'held'],
            [count($refused), 'refused', 'refused'],
            [count($disagreements), 'disagreement', 'disagreements'],
            [count($errors), 'source error', 'source errors'],
        ] as [$n, $singular, $plural]) {
            if ($n > 0) {
                $phrases[] = $n === 1 ? "1 {$singular}" : "{$n} {$plural}";
            }
        }

        return implode(', ', $phrases);
    }

    /**
     * One change as "php 8.3: latest_patch 8.3.35 -> 8.3.36", the
     * refresher's own summary wording.
     */
    private static function summaryLine(object $item): string
    {
        $old = $item->old ?? null;
        $new = $item->new ?? null;

        return sprintf(
            '%s %s: %s %s -> %s',
            (string) ($item->component ?? '?'),
            (string) ($item->line ?? '?'),
            (string) ($item->field ?? '?'),
            $old !== null ? (string) $old : '(none)',
            $new !== null ? (string) $new : '(removed)',
        );
    }

    /**
     * One held or refused item as "refused composer 2.8: minimum 2.8.0 ->
     * (removed) [refused]: <reason>", the refresher's own wording.
     */
    private static function recordLine(string $verb, object $item): string
    {
        $line = "{$verb} " . self::summaryLine($item) . ' [' . (string) ($item->class ?? '?') . ']';
        $reason = is_string($item->reason ?? null) && $item->reason !== '' ? ': ' . $item->reason : '';

        return $line . $reason;
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

    /**
     * The report strings under one field.
     *
     * @return list<string>
     */
    private function strings(object $report, string $field): array
    {
        $values = [];
        foreach ((array) ($report->{$field} ?? []) as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The decoded --report document, or an empty one when the refresher
     * wrote nothing readable.
     */
    private function readReport(string $reportPath): object
    {
        $raw = @file_get_contents($reportPath);
        if ($raw !== false) {
            $decoded = json_decode($raw);
            if (is_object($decoded)) {
                return $decoded;
            }
        }

        return new stdClass();
    }
}
