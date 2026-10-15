<?php

declare(strict_types=1);

namespace Pollora\Role\Application\Services;

use Pollora\Attributes\PostType;
use Pollora\Attributes\PostType\Capabilities as PostTypeCapabilities;
use Pollora\Attributes\PostType\CapabilityType;
use Pollora\Attributes\Taxonomy;
use Pollora\Attributes\Taxonomy\Capabilities as TaxonomyCapabilities;
use ReflectionClass;

/**
 * Reads the capabilities a `#[PostType]` or `#[Taxonomy]` class declares.
 */
final class CapabilityOwnerReader
{
    public const array TERM_CAPABILITIES = ['manage_terms', 'edit_terms', 'delete_terms', 'assign_terms'];

    /**
     * @return array{slug: string, capabilityType: string|null, overrides: array<string, string>}|null Null when the class is not a post type
     */
    public function postType(string $class): ?array
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $postType = $reflection->getAttributes(PostType::class)[0] ?? null;

        if ($postType === null) {
            return null;
        }

        $capabilityType = $reflection->getAttributes(CapabilityType::class)[0] ?? null;
        $capabilities = $reflection->getAttributes(PostTypeCapabilities::class)[0] ?? null;

        return [
            'slug' => $postType->newInstance()->resolveSlug($class),
            'capabilityType' => $capabilityType?->newInstance()->value,
            'overrides' => $capabilities?->newInstance()->value ?? [],
        ];
    }

    /**
     * @return array{slug: string, capabilities: list<string>}|null Null when the class is not a taxonomy
     */
    public function taxonomy(string $class): ?array
    {
        if (! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $taxonomy = $reflection->getAttributes(Taxonomy::class)[0] ?? null;

        if ($taxonomy === null) {
            return null;
        }

        $map = ($reflection->getAttributes(TaxonomyCapabilities::class)[0] ?? null)?->newInstance()->value ?? [];

        return [
            'slug' => $taxonomy->newInstance()->resolveSlug($class),
            'capabilities' => array_values(array_intersect_key($map, array_flip(self::TERM_CAPABILITIES))),
        ];
    }
}
