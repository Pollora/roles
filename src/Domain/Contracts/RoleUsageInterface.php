<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Contracts;

/**
 * What the users of the site hold, read from their stored capabilities.
 *
 * WordPress stores a user's roles and the capabilities given to them one by
 * one in the same array: an entry is a role or a capability depending on
 * whether WordPress knows a role by that name.
 */
interface RoleUsageInterface
{
    /**
     * Every entry users carry (granted), with the number of users carrying it.
     *
     * @return array<string, int>
     */
    public function entries(): array;

    /**
     * The users carrying an entry, by ID, with their login.
     *
     * @return array<int, string>
     */
    public function usersWith(string $entry): array;
}
