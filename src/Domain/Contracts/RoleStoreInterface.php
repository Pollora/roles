<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Contracts;

/**
 * Writes the roles stored in the database and what users carry: only the
 * pollora:roles commands that change data use it, each after a dry run.
 */
interface RoleStoreInterface
{
    /**
     * The roles as stored in the `{prefix}user_roles` option, without the code applied.
     *
     * @return array<string, array<string, mixed>>
     */
    public function storedRoles(): array;

    /**
     * Replaces the stored roles.
     *
     * @param  array<string, array<string, mixed>>  $roles
     */
    public function saveStoredRoles(array $roles): void;

    /**
     * The roles WordPress knows that a user carries.
     *
     * @return list<string>
     */
    public function rolesOf(int $userId): array;

    /**
     * Removes an entry (a role or a capability) from a user's stored capabilities.
     */
    public function removeFromUser(int $userId, string $entry): void;

    /**
     * Gives a user a role, keeping the others.
     */
    public function addRoleToUser(int $userId, string $role): void;
}
