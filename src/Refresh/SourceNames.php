<?php

declare(strict_types=1);

namespace Waynestate\Creed\Refresh;

/**
 * The canonical names of the feeds the refresh tool reads, as they appear
 * in the provenance ledger and the report.
 */
final class SourceNames
{
    public const PHP_STATES = 'php.net/states';

    public const PHP_ACTIVE = 'php.net/active';

    public const NODE_SCHEDULE = 'nodejs/schedule';

    public const NODE_DIST = 'nodejs.org/dist';

    public const EOL_PHP = 'endoflife.date/php';

    public const EOL_NODEJS = 'endoflife.date/nodejs';

    public const EOL_LARAVEL = 'endoflife.date/laravel';

    public const PACKAGIST_LARAVEL = 'packagist/laravel-framework';

    public const EOL_MYSQL = 'endoflife.date/mysql';

    public const EOL_VUE = 'endoflife.date/vue';

    public const NPM_INERTIA = 'npm/@inertiajs/vue3';

    public const EOL_COMPOSER = 'endoflife.date/composer';
}
