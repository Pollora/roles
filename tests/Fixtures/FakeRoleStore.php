<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Role\Domain\Contracts\RoleStoreInterface;

/**
 * Stored roles and users' roles in memory, keeping each write.
 */
final class FakeRoleStore implements RoleStoreInterface
{
    /** @var list<string> */
    public array $writes = [];

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<int, list<string>>  $userRoles
     */
    public function __construct(public array $stored = [], public array $userRoles = []) {}

    public function storedRoles(): array
    {
        return $this->stored;
    }

    public function saveStoredRoles(array $roles): void
    {
        $this->stored = $roles;
        $this->writes[] = 'roles: '.implode(', ', array_keys($roles));
    }

    public function rolesOf(int $userId): array
    {
        return $this->userRoles[$userId] ?? [];
    }

    public function removeFromUser(int $userId, string $entry): void
    {
        $this->writes[] = sprintf('user %d: remove %s', $userId, $entry);
    }

    public function addRoleToUser(int $userId, string $role): void
    {
        $this->userRoles[$userId][] = $role;
        $this->writes[] = sprintf('user %d: add %s', $userId, $role);
    }
}
