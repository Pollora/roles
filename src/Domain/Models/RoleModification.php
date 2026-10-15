<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Models;

/**
 * Capabilities granted to or removed from a role the project does not own, with `#[ModifyRole]`.
 */
final readonly class RoleModification
{
    /**
     * @param  class-string  $declaringClass
     */
    public function __construct(
        public string $slug,
        public RoleChanges $changes,
        public string $declaringClass,
    ) {}

    public function withChanges(RoleChanges $changes): self
    {
        return new self($this->slug, $changes, $this->declaringClass);
    }
}
