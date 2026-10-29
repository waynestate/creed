<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * Decides how risky each proposed change to the policy is.
 *
 * Kinds:
 *   tighten        the line gets less support than before: always safe to write
 *   neutral        no change in what the policy allows: safe to write
 *   loosen-warning the line gets more support, in a way that cannot make an
 *                  app run on a version it could not already run on: written,
 *                  but flagged in the report
 *   loosen-fail    the line would become usable beyond what upstream supports:
 *                  held unless a second source or the ledger agrees
 *   refused        never written automatically (for example, removing a date)
 */
final class ChangeClassifier
{
    public const KIND_TIGHTEN = 'tighten';

    public const KIND_NEUTRAL = 'neutral';

    public const KIND_LOOSEN_WARNING = 'loosen-warning';

    public const KIND_LOOSEN_FAIL = 'loosen-fail';

    public const KIND_REFUSED = 'refused';

    public function __construct(private readonly string $today) {}

    /**
     * Classify a change to one support date of a listed line.
     *
     * @return string a kind, or '' when the value does not change
     */
    public function dateChange(LineContext $before, LineContext $after, ?string $oldValue, ?string $newValue, string $field): string
    {
        if ($oldValue === $newValue) {
            return '';
        }
        if ($oldValue !== null && $newValue !== null) {
            if ($newValue < $oldValue) {
                return self::KIND_TIGHTEN;
            }

            // The date moved later: active support is informational, security
            // support is what keeps the line usable at all.
            return $field === 'active_until' ? self::KIND_LOOSEN_WARNING : self::KIND_LOOSEN_FAIL;
        }
        if ($newValue === null) {
            // Removing a date is never something the tool may do alone.
            return self::KIND_REFUSED;
        }

        // A date was added.
        if ($before->current) {
            // A line that was open-ended gets its first announced end.
            return self::KIND_TIGHTEN;
        }
        if (!$before->supportedAsOf($this->today) && $after->supportedAsOf($this->today)) {
            return self::KIND_LOOSEN_FAIL;
        }
        if ($before->supportedAsOf($this->today) && !$after->supportedAsOf($this->today)) {
            return self::KIND_TIGHTEN;
        }

        // No status change: a past date is historical backfill, a future one
        // extends what the policy will allow.
        return $newValue < $this->today ? self::KIND_NEUTRAL : self::KIND_LOOSEN_WARNING;
    }

    /**
     * Classify a latest_patch change. Patch bumps never change what the
     * policy allows.
     *
     * @return string a kind, or '' when the value does not change
     */
    public function patchChange(?string $oldValue, ?string $newValue): string
    {
        return $oldValue === $newValue ? '' : self::KIND_NEUTRAL;
    }

    /**
     * Classify a newly proposed line by its position against the lines the
     * policy already lists.
     *
     * @param array<int|string, mixed> $listed
     */
    public function newLine(string $candidate, array $listed): string
    {
        return Versions::aboveAll($candidate, $listed) ? self::KIND_NEUTRAL : self::KIND_LOOSEN_FAIL;
    }

    /**
     * A line marked "current" gaining end dates loses its open-ended
     * support: always a tightening.
     */
    public function currentToDated(): string
    {
        return self::KIND_TIGHTEN;
    }
}
