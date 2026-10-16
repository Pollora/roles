<?php

declare(strict_types=1);

namespace Pollora\Role\Application\Services;

use Closure;
use LogicException;
use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Domain\Exceptions\InvalidRoleDefinitionException;
use Pollora\Role\Domain\Models\RoleChanges;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Domain\Models\RoleModification;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;

/**
 * Everything the project declares about roles and capabilities: roles,
 * modifications, post types and taxonomies with their own capabilities,
 * capability sets.
 *
 * Declarations arrive location by location (app, plugins, theme); the checks
 * that involve several classes run as each one is added.
 */
final class RoleRegistry
{
    /** @var array<string, RoleDefinition> */
    private array $definitions = [];

    /** @var list<RoleModification> */
    private array $modifications = [];

    /** @var array<string, array{slug: string, capabilityType: string, overrides: array<string, string>}> Post types with their own capabilities, by class and by slug */
    private array $postTypes = [];

    /** @var array<string, array{slug: string, capabilities: list<string>}> Taxonomies with their own capabilities, by class and by slug */
    private array $taxonomies = [];

    /** @var list<string> */
    private array $capabilitySets = [];

    /** @var list<RoleDeclaration> Fluent declarations, checked when the roles are first read */
    private array $pending = [];

    /** @var list<string> Why a fluent declaration was refused */
    private array $refusals = [];

    /**
     * @param  RoleDefinitionBuilder|null  $builder  Checks the fluent declarations; without it, only classes with attributes can be added
     */
    public function __construct(
        private readonly CapabilityOwnerReader $owners,
        private readonly PostTypeCapabilityMap $postTypeCapabilities,
        private readonly ?RoleDefinitionBuilder $builder = null,
    ) {}

    /**
     * Adds a declaration written with the fluent API. It is checked and added when the roles are
     * first read, once its chain is complete; a mistake is kept in refusals(), not thrown.
     *
     * @throws LogicException When the registry was built without a builder
     */
    public function declare(RoleDeclaration $declaration): void
    {
        if (! $this->builder instanceof RoleDefinitionBuilder) {
            throw new LogicException('This registry has no RoleDefinitionBuilder to check a fluent declaration with.');
        }

        $this->pending[] = $declaration;
    }

    /**
     * Why fluent declarations were refused, each naming the call that made it.
     *
     * @return list<string>
     */
    public function refusals(): array
    {
        $this->settle();

        return $this->refusals;
    }

    /**
     * @throws InvalidRoleDefinitionException When the slug is taken or the inheritance loops
     */
    public function addRole(RoleDefinition $definition): void
    {
        $existing = $this->definitions[$definition->slug] ?? null;

        if ($existing instanceof RoleDefinition && $existing->declaringClass !== $definition->declaringClass) {
            throw InvalidRoleDefinitionException::forClass($definition->declaringClass, sprintf('the role "%s" is already declared by %s.', $definition->slug, $existing->declaringClass));
        }

        $chain = [$definition->slug];
        $parent = $definition->inherits;

        while ($parent !== null) {
            if (in_array($parent, $chain, true)) {
                throw InvalidRoleDefinitionException::forClass($definition->declaringClass, sprintf('inheritance loops: %s.', implode(' → ', [...$chain, $parent])));
            }

            if (! isset($this->definitions[$parent])) {
                break;
            }

            $chain[] = $parent;
            $parent = $this->definitions[$parent]->inherits;
        }

        $this->definitions[$definition->slug] = $definition;
    }

    /**
     * @throws InvalidRoleDefinitionException When another class removes what this one grants, or the reverse
     */
    public function addModification(RoleModification $modification): void
    {
        foreach ($this->modifications as $existing) {
            if ($existing->slug !== $modification->slug || $existing->declaringClass === $modification->declaringClass) {
                continue;
            }

            $conflicts = [
                ...array_intersect($existing->changes->grants, $modification->changes->removals),
                ...array_intersect($existing->changes->removals, $modification->changes->grants),
            ];

            if ($conflicts !== []) {
                throw InvalidRoleDefinitionException::forClass($modification->declaringClass, sprintf(
                    'it contradicts %s on the role "%s": "%s" is both granted and removed.',
                    $existing->declaringClass,
                    $modification->slug,
                    implode('", "', array_unique($conflicts))
                ));
            }
        }

        $this->modifications = [
            ...array_filter($this->modifications, static fn (RoleModification $existing): bool => $existing->declaringClass !== $modification->declaringClass),
            $modification,
        ];
    }

    /**
     * Records a post type class; only one with its own capability type counts.
     */
    public function addPostType(string $class): void
    {
        $postType = $this->owners->postType($class);

        if ($postType === null || $postType['capabilityType'] === null) {
            return;
        }

        $this->postTypes[$class] = $this->postTypes[$postType['slug']] = [
            'slug' => $postType['slug'],
            'capabilityType' => $postType['capabilityType'],
            'overrides' => $postType['overrides'],
        ];
    }

    /**
     * Records a taxonomy class; only one with its own term capabilities counts.
     */
    public function addTaxonomy(string $class): void
    {
        $taxonomy = $this->owners->taxonomy($class);

        if ($taxonomy === null || $taxonomy['capabilities'] === []) {
            return;
        }

        $this->taxonomies[$class] = $this->taxonomies[$taxonomy['slug']] = $taxonomy;
    }

    /**
     * @param  list<string>  $capabilities  The values of a #[CapabilitySet] enum
     */
    public function addCapabilitySet(array $capabilities): void
    {
        $this->capabilitySets = array_values(array_unique([...$this->capabilitySets, ...$capabilities]));
    }

    public function isEmpty(): bool
    {
        return $this->pending === [] && $this->definitions === [] && $this->modifications === [] && $this->postTypes === [] && $this->taxonomies === [] && $this->capabilitySets === [];
    }

    /**
     * Declared roles, with post type and taxonomy grants resolved into capability names.
     *
     * @param  Closure(string): void|null  $warn  Receives the grants that could not be resolved
     * @return list<RoleDefinition>
     */
    public function definitions(?Closure $warn = null): array
    {
        $this->settle();

        return array_values(array_map(
            fn (RoleDefinition $definition): RoleDefinition => $definition->withChanges($this->resolve($definition->changes, $definition->declaringClass, $warn)),
            $this->definitions
        ));
    }

    /**
     * Modifications, with post type and taxonomy grants resolved into capability names.
     *
     * @param  Closure(string): void|null  $warn  Receives the grants that could not be resolved
     * @return list<RoleModification>
     */
    public function modifications(?Closure $warn = null): array
    {
        $this->settle();

        return array_map(
            fn (RoleModification $modification): RoleModification => $modification->withChanges($this->resolve($modification->changes, $modification->declaringClass, $warn)),
            $this->modifications
        );
    }

    /**
     * The text domain of each role label that has one, by label.
     *
     * @return array<string, string>
     */
    public function labelDomains(): array
    {
        $this->settle();

        $domains = [];

        foreach ($this->definitions as $definition) {
            if ($definition->textDomain !== null) {
                $domains[$definition->label] = $definition->textDomain;
            }
        }

        return $domains;
    }

    /**
     * Every capability the project declares, for the super roles.
     *
     * @return list<string>
     */
    public function declaredCapabilities(): array
    {
        $capabilities = $this->capabilitySets;

        foreach ($this->postTypes as $postType) {
            $capabilities = [...$capabilities, ...$this->postTypeCapabilities->capabilities($postType['capabilityType'], Access::Editor, $postType['overrides'])];
        }

        foreach ($this->taxonomies as $taxonomy) {
            $capabilities = [...$capabilities, ...$taxonomy['capabilities']];
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * Checks and adds the fluent declarations waiting, once.
     */
    private function settle(): void
    {
        if ($this->pending === [] || ! $this->builder instanceof RoleDefinitionBuilder) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $declaration) {
            try {
                $built = $this->builder->assemble($declaration);
                $built instanceof RoleDefinition ? $this->addRole($built) : $this->addModification($built);
            } catch (\Throwable $throwable) {
                $this->refusals[] = $throwable->getMessage();
            }
        }
    }

    /**
     * @param  Closure(string): void|null  $warn
     */
    private function resolve(RoleChanges $changes, string $declaringClass, ?Closure $warn): RoleChanges
    {
        $grants = $changes->grants;

        foreach ($changes->postTypeGrants as ['postType' => $reference, 'access' => $access]) {
            if (! isset($this->postTypes[$reference]) && class_exists($reference)) {
                $this->addPostType($reference);
            }

            $postType = $this->postTypes[$reference] ?? null;

            if ($postType === null) {
                $this->warnUnresolved($warn, $declaringClass, sprintf('the post type "%s" was not found with its own capabilities', $reference));

                continue;
            }

            $grants = [...$grants, ...$this->postTypeCapabilities->capabilities($postType['capabilityType'], $access, $postType['overrides'])];
        }

        foreach ($changes->taxonomyGrants as $reference) {
            if (! isset($this->taxonomies[$reference]) && class_exists($reference)) {
                $this->addTaxonomy($reference);
            }

            $taxonomy = $this->taxonomies[$reference] ?? null;

            if ($taxonomy === null) {
                $this->warnUnresolved($warn, $declaringClass, sprintf('the taxonomy "%s" was not found with its own capabilities', $reference));

                continue;
            }

            $grants = [...$grants, ...$taxonomy['capabilities']];
        }

        return $changes->withResolvedGrants($grants);
    }

    /**
     * @param  Closure(string): void|null  $warn
     */
    private function warnUnresolved(?Closure $warn, string $declaringClass, string $reason): void
    {
        if ($warn instanceof Closure) {
            $warn(sprintf('%s: %s; its grant is ignored.', $declaringClass, $reason));
        }
    }
}
