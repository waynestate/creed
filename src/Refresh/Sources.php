<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * Everything the refresh engine needs from the outside world: the parsed
 * feeds, and the errors collected while loading them.
 *
 * Any feed may be null: the engine plans around whatever is present and the
 * command reports what went missing. Nothing here touches the network or the
 * filesystem.
 */
final class Sources
{
    /**
     * @param array<string, array{state: ?string, active_until: ?string, security_until: ?string}>|null $phpStates
     * @param array<int|string, string>|null $phpActive
     * @param array<int|string, array{maintenance: ?string, end: ?string}>|null $nodeSchedule
     * @param array<int|string, string>|null $nodeDist
     * @param list<Release>|null $endoflifePhp
     * @param list<Release>|null $endoflifeNodejs
     * @param list<Release>|null $endoflifeLaravel
     * @param array<int|string, string>|null $packagistLaravel
     * @param list<Release>|null $endoflifeMysql
     * @param list<Release>|null $endoflifeVue
     * @param array<int|string, string>|null $npmInertia
     * @param list<Release>|null $endoflifeComposer
     * @param list<string> $errors
     */
    public function __construct(
        public readonly ?array $phpStates = null,
        public readonly ?array $phpActive = null,
        public readonly ?array $nodeSchedule = null,
        public readonly ?array $nodeDist = null,
        public readonly ?array $endoflifePhp = null,
        public readonly ?array $endoflifeNodejs = null,
        public readonly ?array $endoflifeLaravel = null,
        public readonly ?array $packagistLaravel = null,
        public readonly ?array $endoflifeMysql = null,
        public readonly ?array $endoflifeVue = null,
        public readonly ?array $npmInertia = null,
        public readonly ?array $endoflifeComposer = null,
        public readonly array $errors = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}
