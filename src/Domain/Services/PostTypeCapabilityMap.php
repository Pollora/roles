<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Services;

use Pollora\Role\Domain\Enums\Access;

/**
 * Names the capabilities of a post type with its own capability type, the way
 * `get_post_type_capabilities()` does: `edit_posts` becomes `edit_events` for
 * the capability type `event`, unless `#[Capabilities]` maps it to another name.
 *
 * Computed from the post type's attributes rather than read from WordPress:
 * roles are injected before `init`, where post types are registered.
 */
final class PostTypeCapabilityMap
{
    /**
     * @param  string  $capabilityType  The singular capability type (`event`)
     * @param  array<string, string>  $overrides  The #[Capabilities] map, by generic name
     * @return list<string>
     */
    public function capabilities(string $capabilityType, Access $access, array $overrides = []): array
    {
        $plural = $capabilityType.'s';

        return array_map(
            static fn (string $generic): string => $overrides[$generic] ?? str_replace('_posts', '_'.$plural, $generic),
            $access->genericCapabilities()
        );
    }
}
