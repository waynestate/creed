<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

use stdClass;

/**
 * What one refresh run found: approved changes, held changes, refused
 * changes, source disagreements, and the errors that kept feeds from
 * loading. The command turns this into output, exit codes, and the report.
 */
final class RefreshResult
{
    /** @var list<ChangeItem> */
    public array $changes = [];

    /** @var list<ChangeItem> */
    public array $held = [];

    /** @var list<ChangeItem> */
    public array $refused = [];

    /** @var list<Disagreement> */
    public array $disagreements = [];

    public function addChange(ChangeItem $item): void
    {
        $this->changes[] = $item;
    }

    public function addHeld(ChangeItem $item): void
    {
        $this->held[] = $item;
    }

    public function addRefused(ChangeItem $item): void
    {
        $this->refused[] = $item;
    }

    public function addDisagreement(Disagreement $disagreement): void
    {
        $this->disagreements[] = $disagreement;
    }

    /**
     * Whether any written change loosens support past what upstream says.
     * A released loosen-fail is real (a second source or the ledger agreed),
     * so the run exits 3 like any other written change; the policy robot
     * raises the alert from the report instead of holding the commit.
     */
    public function hasLoosenFailChanges(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->kind === ChangeClassifier::KIND_LOOSEN_FAIL) {
                return true;
            }
        }

        return false;
    }

    public function anythingToReport(): bool
    {
        return $this->changes !== []
            || $this->held !== []
            || $this->refused !== []
            || $this->disagreements !== [];
    }

    /**
     * Build the --report document.
     *
     * @param list<string> $sourceErrors
     */
    public function reportObject(string $today, bool $dryRun, array $sourceErrors): stdClass
    {
        $root = new stdClass();
        $root->generated_on = $today;
        $root->dry_run = $dryRun;
        $root->changes = array_map(self::itemToObject(...), $this->changes);
        $root->held = array_map(self::itemToObject(...), $this->held);
        $root->refused = array_map(self::itemToObject(...), $this->refused);
        $root->disagreements = array_map(self::disagreementToObject(...), $this->disagreements);
        $root->source_errors = $sourceErrors;

        return $root;
    }

    private static function itemToObject(ChangeItem $item): stdClass
    {
        $obj = new stdClass();
        $obj->component = $item->component;
        $obj->line = $item->line;
        $obj->field = $item->field;
        $obj->old = $item->oldValue;
        $obj->new = $item->newValue;
        $obj->class = $item->kind;
        $obj->sources = [$item->source, ...$item->agreement];
        $obj->reason = $item->reason;

        return $obj;
    }

    private static function disagreementToObject(Disagreement $disagreement): stdClass
    {
        $obj = new stdClass();
        $obj->component = $disagreement->component;
        $obj->line = $disagreement->line;
        $obj->field = $disagreement->field;
        $obj->primary_source = $disagreement->primarySource;
        $obj->primary_value = $disagreement->primaryValue;
        $obj->secondary_source = $disagreement->secondarySource;
        $obj->secondary_value = $disagreement->secondaryValue;

        return $obj;
    }
}
