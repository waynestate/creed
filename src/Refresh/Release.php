<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * One release line from an endoflife.date product feed.
 */
final readonly class Release
{
    public function __construct(
        public string $name,
        public bool $isEol,
        public ?string $eolFrom,
        public ?string $eoasFrom,
        public ?bool $isLts,
        public ?string $latest,
    ) {}
}
