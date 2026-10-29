<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * One proposed (or held, or refused) change to the policy.
 */
final readonly class ChangeItem
{
    public function __construct(
        public string $component,
        public string $line,
        public string $field,
        public ?string $oldValue,
        public ?string $newValue,
        public string $kind,
        public string $source,
        public string $reason = '',
        /**
         * For "entry" items only: the fields of the proposed version line,
         * for example ['current' => true] or ['security_until' => '...'].
         *
         * @var array<string, mixed>|null
         */
        public ?array $entry = null,
        /**
         * What released a held change: a second source, or the ledger.
         *
         * @var list<string>
         */
        public array $agreement = [],
    ) {}

    /**
     * The same change, marked as confirmed by a second source or the ledger.
     */
    public function agreedBy(string $by): self
    {
        return new self(
            $this->component,
            $this->line,
            $this->field,
            $this->oldValue,
            $this->newValue,
            $this->kind,
            $this->source,
            $this->reason,
            $this->entry,
            [...$this->agreement, $by],
        );
    }

    /**
     * A human-readable summary, for example
     * "php 8.1: active_until (none) -> 2023-11-25".
     */
    public function summary(): string
    {
        $old = $this->oldValue ?? '(none)';
        $new = $this->newValue ?? '(removed)';

        return "{$this->component} {$this->line}: {$this->field} {$old} -> {$new}";
    }
}
