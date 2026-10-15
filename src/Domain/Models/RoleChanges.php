<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Models;

use Pollora\Role\Domain\Enums\Access;

/**
 * The capabilities a role declaration grants and removes.
 *
 * Post type and taxonomy grants are references until the registry resolves them
 * into capability names, which may only be possible once the post type or the
 * taxonomy has been discovered.
 */
final readonly class RoleChanges
{
    /**
     * @param  list<string>  $grants  Capability names granted
     * @param  list<string>  $removals  Capability names removed
     * @param  list<array{postType: string, access: Access}>  $postTypeGrants  Post types (class or slug) granted at a level
     * @param  list<string>  $taxonomyGrants  Taxonomies (class or slug) whose term capabilities are granted
     */
    public function __construct(
        public array $grants = [],
        public array $removals = [],
        public array $postTypeGrants = [],
        public array $taxonomyGrants = [],
    ) {}

    /**
     * @param  list<string>  $grants
     */
    public function withResolvedGrants(array $grants): self
    {
        return new self(array_values(array_unique($grants)), $this->removals);
    }
}
