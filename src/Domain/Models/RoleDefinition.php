<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Models;

/**
 * A role the project declares with `#[Role]`.
 */
final readonly class RoleDefinition
{
    /**
     * @param  string  $declaringClass  The class that declares it, or the call that does
     */
    public function __construct(
        public string $slug,
        public string $label,
        public ?string $inherits,
        public RoleChanges $changes,
        public string $declaringClass,
        public ?string $textDomain = null,
    ) {}

    public function withChanges(RoleChanges $changes): self
    {
        return new self($this->slug, $this->label, $this->inherits, $changes, $this->declaringClass, $this->textDomain);
    }
}
