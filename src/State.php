<?php

declare(strict_types=1);

namespace Waynestate\Creed;

/**
 * What state a version is in, derived from the policy and a date.
 *
 * The values are the words the stack uses in reports and gates.
 */
enum State: string
{
    case Active = 'active';
    case Security = 'security';
    case Eol = 'eol';
    case EolGraceExpired = 'eol_grace_expired';
}
