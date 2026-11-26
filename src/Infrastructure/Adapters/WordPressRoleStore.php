<?php

declare(strict_types=1);

namespace Pollora\Role\Infrastructure\Adapters;

use Pollora\Role\Domain\Contracts\RoleStoreInterface;

/**
 * Writes through WordPress's own API — `update_option()` and `WP_User` — so
 * caches are cleared and the user hooks fire.
 */
final readonly class WordPressRoleStore implements RoleStoreInterface
{
    public function storedRoles(): array
    {
        $option = \get_option($this->optionName(), []);

        // Returned as stored, nothing filtered or rebuilt: what is read here is what saveStoredRoles() writes back.
        // The shape asserted is the one WordPress writes in this option.
        /** @var array<string, array<string, mixed>> $roles */
        $roles = is_array($option) ? $option : [];

        return $roles;
    }

    public function saveStoredRoles(array $roles): void
    {
        \update_option($this->optionName(), $roles);
    }

    public function rolesOf(int $userId): array
    {
        $user = \get_userdata($userId);

        return $user instanceof \WP_User ? array_values($user->roles) : [];
    }

    public function removeFromUser(int $userId, string $entry): void
    {
        $user = \get_userdata($userId);

        // remove_cap(), not remove_role(): WP_User::$roles only lists roles WordPress
        // knows, so remove_role() ignores a role that no longer exists
        if ($user instanceof \WP_User) {
            $user->remove_cap($entry);
        }
    }

    public function addRoleToUser(int $userId, string $role): void
    {
        $user = \get_userdata($userId);

        if ($user instanceof \WP_User) {
            $user->add_role($role);
        }
    }

    private function optionName(): string
    {
        /** @var \wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        return $wpdb->get_blog_prefix().'user_roles';
    }
}
