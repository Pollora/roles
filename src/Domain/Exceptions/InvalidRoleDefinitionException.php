<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Exceptions;

use LogicException;

/**
 * A role declaration that would grant the wrong rights, or none.
 *
 * Raised at discovery, with the class named, so that the mistake shows before
 * anyone logs in with the role.
 */
final class InvalidRoleDefinitionException extends LogicException
{
    public static function forClass(string $class, string $reason): self
    {
        return new self(sprintf('%s: %s', $class, $reason));
    }
}
