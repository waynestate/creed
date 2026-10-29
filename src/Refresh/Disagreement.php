<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * Two sources saying different things about the same fact. The primary
 * source wins; the disagreement is reported, never acted on.
 */
final readonly class Disagreement
{
    public function __construct(
        public string $component,
        public string $line,
        public string $field,
        public string $primarySource,
        public string $primaryValue,
        public string $secondarySource,
        public string $secondaryValue,
    ) {}

    public function summary(): string
    {
        return "{$this->component} {$this->line} {$this->field}: "
            . "{$this->primarySource} says {$this->primaryValue}, "
            . "{$this->secondarySource} says {$this->secondaryValue}";
    }
}
