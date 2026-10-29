<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * The support state of one policy line, as far as classification cares:
 * whether it is marked current, and its two support dates.
 */
final readonly class LineContext
{
    public function __construct(
        public bool $current,
        public ?string $activeUntil,
        public ?string $securityUntil,
    ) {}

    /**
     * Build the context of a listed policy entry.
     */
    public static function fromEntry(object $entry): self
    {
        $vars = get_object_vars($entry);
        $current = ($vars['current'] ?? null) === true;
        $active = isset($vars['active_until']) && is_string($vars['active_until']) ? $vars['active_until'] : null;
        $security = isset($vars['security_until']) && is_string($vars['security_until']) ? $vars['security_until'] : null;

        return new self($current, $active, $security);
    }

    /**
     * Whether the line is still supported on the given day: a current line
     * always is, a dated line until its security_until (inclusive).
     */
    public function supportedAsOf(string $today): bool
    {
        return $this->current || ($this->securityUntil !== null && $this->securityUntil >= $today);
    }
}
