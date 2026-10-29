<?php

declare(strict_types=1);

namespace Waynestate\Creed\Bot;

use RuntimeException;

/**
 * A command could not be started, or its transport misbehaved.
 */
final class CommandException extends RuntimeException {}
