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
        $reflection = new ReflectionClass($class);
        $role = ($reflection->getAttributes(Role::class)[0] ?? null)?->newInstance();
        $modifyRole = ($reflection->getAttributes(ModifyRole::class)[0] ?? null)?->newInstance();

        if ($role instanceof Role && $modifyRole instanceof ModifyRole) {
            throw InvalidRoleDefinitionException::forClass($class, 'a class declares a role (#[Role]) or modifies one (#[ModifyRole]), not both.');
        }

        if (! $role instanceof Role && ! $modifyRole instanceof ModifyRole) {
            throw InvalidRoleDefinitionException::forClass($class, 'it carries neither #[Role] nor #[ModifyRole].');
        }

        $changes = $this->changes($reflection, $role->allowSensitive ?? $modifyRole->allowSensitive);

        if ($modifyRole instanceof ModifyRole) {
            return new RoleModification($modifyRole->slug, $changes, $class);
        }

        if (in_array($role->slug, self::CORE_ROLES, true)) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf('"%s" is a core role; adjust it with #[ModifyRole(\'%s\')] instead of redeclaring it.', $role->slug, $role->slug));
        }

        $inherits = $role->inherits === null ? null : $this->resolveRoleSlug($class, $role->inherits);

        if ($inherits !== null && in_array($inherits, $this->superRoles, true)) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf('a role cannot inherit from the super role "%s".', $inherits));
        }

        return new RoleDefinition(
            slug: $role->slug,
            label: $role->label ?? Naming::headline(Naming::classBasename($class)),
            inherits: $inherits,
            changes: $changes,
            declaringClass: $class,
            textDomain: $role->textDomain,
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function changes(ReflectionClass $reflection, bool $allowSensitive): RoleChanges
    {
        $class = $reflection->getName();
        $grants = $this->capabilities($reflection, Grants::class);
        $removals = $this->capabilities($reflection, Without::class);

        $both = array_intersect($grants, $removals);
        if ($both !== []) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf('"%s" is both granted and removed.', implode('", "', $both)));
        }

        $sensitive = array_intersect($grants, self::SENSITIVE_CAPABILITIES);
        if ($sensitive !== [] && ! $allowSensitive) {
            throw InvalidRoleDefinitionException::forClass($class, sprintf(
                'granting "%s" lets a user take over the site; confirm it with allowSensitive: true.',
                implode('", "', $sensitive)
            ));
        }

        $postTypeGrants = [];
        foreach ($reflection->getAttributes(GrantsPostType::class) as $attribute) {
            $grant = $attribute->newInstance();
            $this->assertPostTypeHasCapabilities($class, $grant->postType);
            $postTypeGrants[] = ['postType' => $grant->postType, 'access' => $grant->access];
        }

        $taxonomyGrants = [];
        foreach ($reflection->getAttributes(GrantsTaxonomy::class) as $attribute) {
            $taxonomy = $attribute->newInstance()->taxonomy;
            $this->assertTaxonomyHasCapabilities($class, $taxonomy);
            $taxonomyGrants[] = $taxonomy;
        }

        return new RoleChanges(array_values(array_unique($grants)), array_values(array_unique($removals)), $postTypeGrants, $taxonomyGrants);
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  class-string<Grants|Without>  $attributeClass
     * @return list<string>
     */
    private function capabilities(ReflectionClass $reflection, string $attributeClass): array
    {
        $capabilities = [];

        foreach ($reflection->getAttributes($attributeClass) as $attribute) {
            foreach ($attribute->newInstance()->capabilities as $capability) {
                $capabilities[] = $capability instanceof BackedEnum ? (string) $capability->value : $capability;
            }
        }

        return $capabilities;
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
