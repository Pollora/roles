<?php

declare(strict_types=1);

namespace Pollora\Role\Application\Services;

use BackedEnum;
use Pollora\Attributes\ModifyRole;
use Pollora\Attributes\Role;
use Pollora\Attributes\Role\Grants;
use Pollora\Attributes\Role\GrantsPostType;
use Pollora\Attributes\Role\GrantsTaxonomy;
use Pollora\Attributes\Role\Without;
use Pollora\Attributes\Support\Naming;
use Pollora\Role\Domain\Exceptions\InvalidRoleDefinitionException;
use Pollora\Role\Domain\Models\RoleChanges;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Domain\Models\RoleModification;
use ReflectionClass;

/**
 * Builds a role definition or modification from a `#[Role]` or `#[ModifyRole]`
 * class, refusing what would grant the wrong rights.
 */
final readonly class RoleDefinitionBuilder
{
    /**
     * Core roles: redefining one goes through #[ModifyRole].
     */
    public const array CORE_ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];

    /**
     * Capabilities that let a user take over the site; granting one needs `allowSensitive: true`.
     */
    public const array SENSITIVE_CAPABILITIES = [
        'manage_options', 'edit_users', 'create_users', 'delete_users', 'promote_users',
        'unfiltered_html', 'unfiltered_upload', 'install_plugins', 'activate_plugins',
        'edit_plugins', 'edit_themes', 'edit_files', 'update_core',
    ];

    /**
     * @param  list<string>  $superRoles  Roles that may not be inherited from
     */
    public function __construct(
        private CapabilityOwnerReader $owners,
        private array $superRoles = ['administrator'],
    ) {}

    /**
     * @param  class-string  $class
     *
     * @throws InvalidRoleDefinitionException
     */
    public function build(string $class): RoleDefinition|RoleModification
    {
        return $this->assemble($this->declarationOf($class));
    }

    /**
     * Checks a declaration, however it was written, and turns it into a definition or a modification.
     *
     * @throws InvalidRoleDefinitionException
     */
    public function assemble(RoleDeclaration $declaration): RoleDefinition|RoleModification
    {
        $source = $declaration->source();
        $changes = $this->changes($declaration);

        if ($declaration->isModification()) {
            return new RoleModification($declaration->slug(), $changes, $source);
        }

        if (in_array($declaration->slug(), self::CORE_ROLES, true)) {
            throw InvalidRoleDefinitionException::forClass($source, sprintf('"%s" is a core role; adjust it with #[ModifyRole(\'%s\')] instead of redeclaring it.', $declaration->slug(), $declaration->slug()));
        }

        $inherits = $declaration->inheritedRole() === null ? null : $this->resolveRoleSlug($source, $declaration->inheritedRole());

        if ($inherits !== null && in_array($inherits, $this->superRoles, true)) {
            throw InvalidRoleDefinitionException::forClass($source, sprintf('a role cannot inherit from the super role "%s".', $inherits));
        }

        return new RoleDefinition(
            slug: $declaration->slug(),
            label: $declaration->label() ?? Naming::headline(Naming::classBasename($source)),
            inherits: $inherits,
            changes: $changes,
            declaringClass: $source,
            textDomain: $declaration->labelDomain(),
        );
    }

    /**
     * Reads the attributes of a class into a declaration.
     *
     * @param  class-string  $class
     */
    private function declarationOf(string $class): RoleDeclaration
    {
        $reflection = new ReflectionClass($class);
        $role = ($reflection->getAttributes(Role::class)[0] ?? null)?->newInstance();
        $modifyRole = ($reflection->getAttributes(ModifyRole::class)[0] ?? null)?->newInstance();

        if ($role instanceof Role && $modifyRole instanceof ModifyRole) {
            throw InvalidRoleDefinitionException::forClass($class, 'a class declares a role (#[Role]) or modifies one (#[ModifyRole]), not both.');
        }

        if (! $role instanceof Role && ! $modifyRole instanceof ModifyRole) {
            throw InvalidRoleDefinitionException::forClass($class, 'it carries neither #[Role] nor #[ModifyRole].');
        }

        $declaration = new RoleDeclaration(
            $role instanceof Role ? $role->slug : $modifyRole->slug,
            $role instanceof Role ? $role->label : null,
            $modifyRole instanceof ModifyRole,
            $class,
        );
        $declaration->allowSensitive($role->allowSensitive ?? $modifyRole->allowSensitive);

        if ($role instanceof Role) {
            if ($role->inherits !== null) {
                $declaration->inherits($role->inherits);
            }

            if ($role->textDomain !== null) {
                $declaration->textDomain($role->textDomain);
            }
        }

        foreach ($reflection->getAttributes(Grants::class) as $attribute) {
            $declaration->grants(...$attribute->newInstance()->capabilities);
        }

        foreach ($reflection->getAttributes(Without::class) as $attribute) {
            $declaration->without(...$attribute->newInstance()->capabilities);
        }

        foreach ($reflection->getAttributes(GrantsPostType::class) as $attribute) {
            $grant = $attribute->newInstance();
            $declaration->grantsPostType($grant->postType, $grant->access);
        }

        foreach ($reflection->getAttributes(GrantsTaxonomy::class) as $attribute) {
            $declaration->grantsTaxonomy($attribute->newInstance()->taxonomy);
        }

        return $declaration;
    }

    private function changes(RoleDeclaration $declaration): RoleChanges
    {
        $source = $declaration->source();
        $grants = $this->names($declaration->grantedCapabilities());
        $removals = $this->names($declaration->removedCapabilities());

        $both = array_intersect($grants, $removals);
        if ($both !== []) {
            throw InvalidRoleDefinitionException::forClass($source, sprintf('"%s" is both granted and removed.', implode('", "', $both)));
        }

        $sensitive = array_intersect($grants, self::SENSITIVE_CAPABILITIES);
        if ($sensitive !== [] && ! $declaration->confirmsSensitive()) {
            throw InvalidRoleDefinitionException::forClass($source, sprintf(
                'granting "%s" lets a user take over the site; confirm it with allowSensitive: true.',
                implode('", "', $sensitive)
            ));
        }

        $postTypeGrants = [];
        foreach ($declaration->postTypeGrants() as $grant) {
            $this->assertPostTypeHasCapabilities($source, $grant['postType']);
            $postTypeGrants[] = $grant;
        }

        $taxonomyGrants = [];
        foreach ($declaration->taxonomyGrants() as $taxonomy) {
            $this->assertTaxonomyHasCapabilities($source, $taxonomy);
            $taxonomyGrants[] = $taxonomy;
        }

        return new RoleChanges(array_values(array_unique($grants)), array_values(array_unique($removals)), $postTypeGrants, $taxonomyGrants);
    }

    /**
     * @param  list<string|BackedEnum>  $capabilities
     * @return list<string>
     */
    private function names(array $capabilities): array
    {
        return array_map(static fn (string|BackedEnum $capability): string => $capability instanceof BackedEnum ? (string) $capability->value : $capability, $capabilities);
    }

    private function resolveRoleSlug(string $class, string $reference): string
    {
        if (! class_exists($reference)) {
            return $reference;
        }

        $role = ((new ReflectionClass($reference))->getAttributes(Role::class)[0] ?? null)?->newInstance();

        return $role instanceof Role ? $role->slug : throw InvalidRoleDefinitionException::forClass($class, sprintf('it inherits from %s, which is not a #[Role].', $reference));
    }

    private function assertPostTypeHasCapabilities(string $class, string $postType): void
    {
        if (! class_exists($postType)) {
            return;
        }

        $owner = $this->owners->postType($postType);

        if ($owner === null) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf('#[GrantsPostType] names %s, which is not a #[PostType].', $postType));
        }

        if ($owner['capabilityType'] === null) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf(
                'the post type "%s" shares the capabilities of posts; give %s its own with #[CapabilityType(\'%s\')] and #[MapMetaCap].',
                $owner['slug'],
                $postType,
                str_replace('-', '_', $owner['slug'])
            ));
        }
    }

    private function assertTaxonomyHasCapabilities(string $class, string $taxonomy): void
    {
        if (! class_exists($taxonomy)) {
            return;
        }

        $owner = $this->owners->taxonomy($taxonomy);

        if ($owner === null) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf('#[GrantsTaxonomy] names %s, which is not a #[Taxonomy].', $taxonomy));
        }

        if ($owner['capabilities'] === []) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf(
                'the taxonomy "%s" shares the capabilities of categories; give %s its own with #[Capabilities([...])] (manage_terms, edit_terms, delete_terms, assign_terms).',
                $owner['slug'],
                $taxonomy
            ));
        }
    }
}
