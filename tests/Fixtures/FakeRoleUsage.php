<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Role\Domain\Contracts\RoleUsageInterface;

/**
 * Stored user capabilities in memory, by user ID and login.
 */
final readonly class FakeRoleUsage implements RoleUsageInterface
{
    /**
     * @param  array<int, array{login: string, capabilities: array<string, bool>}>  $users
     */
    public function __construct(private array $users = []) {}

    public function entries(): array
    {
        $entries = [];

        foreach ($this->users as $user) {
            foreach (array_keys(array_filter($user['capabilities'])) as $entry) {
                $entries[$entry] = ($entries[$entry] ?? 0) + 1;
            }
        }

        ksort($entries);

        return $entries;
    }

    public function usersWith(string $entry): array
    {
        $users = [];

        foreach ($this->users as $id => $user) {
            if (! empty($user['capabilities'][$entry])) {
                $users[$id] = $user['login'];
            }
        }

        return $users;
    }
}
