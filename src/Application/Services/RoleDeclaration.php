<?php

declare(strict_types=1);

namespace Pollora\Role\Application\Services;

use BackedEnum;
use LogicException;
use Pollora\Role\Domain\Enums\Access;

/**
 * What a project says about one role, before it is checked: a role it declares
 * (`Role::make()`, `#[Role]`) or a role it adjusts (`Role::modify()`, `#[ModifyRole]`).
 *
 * Attributes and the fluent API both fill this, and RoleDefinitionBuilder
 * turns it into a definition: the same checks, the same reasons.
 */
final class RoleDeclaration
{
    private ?string $inherits = null;

    private ?string $textDomain = null;

    private bool $allowSensitive = false;

    /** @var list<string|BackedEnum> */
    private array $grants = [];

    /** @var list<string|BackedEnum> */
    private array $removals = [];

    /** @var list<array{postType: string, access: Access}> */
    private array $postTypeGrants = [];

    /** @var list<string> */
    private array $taxonomyGrants = [];

    /**
     * @param  string  $source  Where it was declared, named in what is refused: a class, or the call
     */
    public function __construct(
        private readonly string $slug,
        private readonly ?string $label,
        private readonly bool $modifies,
        private readonly string $source,
    ) {}

    /**
     * The role this one starts from: its slug, or the class that declares it.
     */
    public function inherits(string $role): static
    {
        $this->assertDeclaresARole(__FUNCTION__);
        $this->inherits = $role;

        return $this;
    }

    /**
     * Capabilities granted, by name or from a backed enum.
     */
    public function grants(string|BackedEnum ...$capabilities): static
    {
        $this->grants = [...$this->grants, ...array_values($capabilities)];

        return $this;
    }

    /**
     * Capabilities removed from what the role inherits.
     */
    public function without(string|BackedEnum ...$capabilities): static
    {
        $this->removals = [...$this->removals, ...array_values($capabilities)];

        return $this;
    }

    /**
     * The capabilities of a post type (class or slug) at a level of access.
     */
    public function grantsPostType(string $postType, Access $access = Access::Editor): static
    {
        $this->postTypeGrants[] = ['postType' => $postType, 'access' => $access];

        return $this;
    }

    /**
     * The term capabilities of a taxonomy (class or slug).
     */
    public function grantsTaxonomy(string $taxonomy): static
    {
        $this->taxonomyGrants[] = $taxonomy;

        return $this;
    }

    /**
     * Confirms that granting a capability which lets a user take over the site is intended.
     */
    public function allowSensitive(bool $allow = true): static
    {
        $this->allowSensitive = $allow;

        return $this;
    }

    /**
     * The text domain the label is translated with.
     */
    public function textDomain(string $textDomain): static
    {
        $this->assertDeclaresARole(__FUNCTION__);
        $this->textDomain = $textDomain;

        return $this;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function isModification(): bool
    {
        return $this->modifies;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function inheritedRole(): ?string
    {
        return $this->inherits;
    }

    public function labelDomain(): ?string
    {
        return $this->textDomain;
    }

    public function confirmsSensitive(): bool
    {
        return $this->allowSensitive;
    }

    /**
     * @return list<string|BackedEnum>
     */
    public function grantedCapabilities(): array
    {
        return $this->grants;
    }

    /**
     * @return list<string|BackedEnum>
     */
    public function removedCapabilities(): array
    {
        return $this->removals;
    }

    /**
     * @return list<array{postType: string, access: Access}>
     */
    public function postTypeGrants(): array
    {
        return $this->postTypeGrants;
    }

    /**
     * @return list<string>
     */
    public function taxonomyGrants(): array
    {
        return $this->taxonomyGrants;
    }

    private function assertDeclaresARole(string $method): void
    {
        if ($this->modifies) {
            throw new LogicException(sprintf('%s: %s() does not apply to a role that is modified, only to one that is declared.', $this->source, $method));
        }
    }
}
