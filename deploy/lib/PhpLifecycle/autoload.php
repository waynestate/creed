<?php

declare(strict_types=1);

/*
 * The /php page's server side, loaded without Composer: the deploy layer's
 * vendor folder does not carry it, and the root test suite requires it the
 * same way.
 */

require_once __DIR__ . '/Constraint.php';
require_once __DIR__ . '/Parse.php';
require_once __DIR__ . '/Merge.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Feed.php';
