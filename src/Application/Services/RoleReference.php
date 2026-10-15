<?php

declare(strict_types=1);

namespace Pollora\Role\Application\Services;

use InvalidArgumentException;
use Pollora\Attributes\ModifyRole;
use Pollora\Attributes\Role;
use ReflectionClass;

/**
 * A role named by its slug (`'editor'`) or by a class carrying `#[Role]` or
 * `#[ModifyRole]` (`EventManager::class`), as `hasRole()`, the `role:`
 * middleware and `@role` accept it.
 */
final class RoleReference
{
    /** @var array<class-string, string> */
    private static array $slugs = [];

    /**
     * @throws InvalidArgumentException When a class is given that carries neither #[Role] nor #[ModifyRole]
     */
    public static function slug(string $role): string
    {
        if (! class_exists($role)) {
            return $role;
        }

        return self::$slugs[$role] ??= self::slugOfClass($role);
    }

    /**
     * Whether one of the roles a user has is among the given ones, ignoring case
     * as `@role` always has.
     *
     * @param  array<array-key, string>  $userRoles  The slugs the user has
     * @param  array<array-key, string>  $roles  Slugs or role classes
     */
    public static function matchesAny(array $userRoles, array $roles): bool
    {
        $userRoles = array_map(strtolower(...), $userRoles);

        foreach ($roles as $role) {
            if (in_array(strtolower(self::slug($role)), $userRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  class-string  $class
     */
    private static function slugOfClass(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $attribute = ($reflection->getAttributes(Role::class)[0] ?? $reflection->getAttributes(ModifyRole::class)[0] ?? null)?->newInstance();

        if (! $attribute instanceof Role && ! $attribute instanceof ModifyRole) {
            throw new InvalidArgumentException(sprintf('%s does not name a role: it carries neither #[Role] nor #[ModifyRole].', $class));
        }

        return $attribute->slug;
    }
}
