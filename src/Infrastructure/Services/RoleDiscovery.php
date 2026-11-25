<?php

declare(strict_types=1);

namespace Pollora\Role\Infrastructure\Services;

use BackedEnum;
use Pollora\Attributes\CapabilitySet;
use Pollora\Attributes\ModifyRole;
use Pollora\Attributes\PostType;
use Pollora\Attributes\Role;
use Pollora\Attributes\Taxonomy;
use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryLocationInterface;
use Pollora\Discovery\Domain\Contracts\ReflectionCacheInterface;
use Pollora\Discovery\Domain\Services\IsDiscovery;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Exceptions\InvalidRoleDefinitionException;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleInjector;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredEnum;
use Spatie\StructureDiscoverer\Data\DiscoveredStructure;

/**
 * Discovers `#[Role]`, `#[ModifyRole]` and `#[CapabilitySet]` declarations, and
 * the post types and taxonomies whose capabilities roles can be granted.
 *
 * Each apply() adds what it found to the registry, then asks the injector to
 * refresh: declarations from a plugin or a theme arrive after WordPress has
 * built its roles.
 */
final class RoleDiscovery implements DiscoveryInterface
{
    use IsDiscovery;

    private const array KINDS = [
        Role::class => 'role',
        ModifyRole::class => 'role',
        CapabilitySet::class => 'capabilitySet',
        PostType::class => 'postType',
        Taxonomy::class => 'taxonomy',
    ];

    public function __construct(
        private readonly RoleDefinitionBuilder $builder,
        private readonly RoleRegistry $registry,
        private readonly WordPressRoleInjector $injector,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function discover(DiscoveryLocationInterface $location, DiscoveredStructure $structure, ?ReflectionCacheInterface $reflectionCache = null): void
    {
        $isClass = $structure instanceof DiscoveredClass && ! $structure->isAbstract;

        if (! $isClass && ! $structure instanceof DiscoveredEnum) {
            return;
        }

        foreach ($structure->attributes as $attribute) {
            $kind = self::KINDS[$attribute->class] ?? null;

            if ($kind === null || ($kind === 'capabilitySet') === $isClass) {
                continue;
            }

            $this->getItems()->add($location, ['class' => $structure->namespace.'\\'.$structure->name, 'kind' => $kind]);

            return;
        }
    }

    public function apply(): void
    {
        foreach ($this->getItems() as $item) {
            // discover() stores arrays of a class name and a kind; anything else is not ours.
            if (! is_array($item) || ! is_string($item['class'] ?? null) || ! is_string($item['kind'] ?? null)) {
                continue;
            }

            $class = $item['class'];
            $kind = $item['kind'];

            if (! class_exists($class) && ! enum_exists($class)) {
                $this->logger?->error(sprintf('Failed to register the role declaration %s: the class cannot be loaded.', $class));

                continue;
            }

            try {
                match ($kind) {
                    'role' => $this->addRole($class),
                    'capabilitySet' => $this->registry->addCapabilitySet($this->valuesOf($class)),
                    'postType' => $this->registry->addPostType($class),
                    'taxonomy' => $this->registry->addTaxonomy($class),
                };
            } catch (\Throwable $throwable) {
                $this->logger?->error(sprintf('Failed to register the role declaration %s: %s', $class, $throwable->getMessage()), ['exception' => $throwable]);
            }
        }

        $this->injector->refresh();
    }

    public function getIdentifier(): string
    {
        return 'roles';
    }

    /**
     * The values of a backed enum of capabilities.
     *
     * @param  class-string  $enum
     * @return list<string>
     *
     * @throws InvalidRoleDefinitionException When the enum is not backed
     */
    private function valuesOf(string $enum): array
    {
        if (! is_a($enum, BackedEnum::class, true)) {
            throw InvalidRoleDefinitionException::forClass($enum, '#[CapabilitySet] needs a backed enum.');
        }

        return array_values(array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));
    }

    /**
     * @param  class-string  $class
     */
    private function addRole(string $class): void
    {
        $declaration = $this->builder->build($class);

        if ($declaration instanceof RoleDefinition) {
            $this->registry->addRole($declaration);
        } else {
            $this->registry->addModification($declaration);
        }
    }
}
