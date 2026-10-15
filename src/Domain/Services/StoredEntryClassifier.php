<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Services;

/**
 * Sorts what users carry in their stored capabilities. WordPress keeps a
 * user's roles and the capabilities given to them one by one in the same
 * array, so an entry is told apart by what WordPress knows:
 *
 *  - a role WordPress has;
 *  - a capability some role grants, given to the user individually;
 *  - neither: a role that no longer exists — removed from the code, most
 *    likely — which gives the user nothing.
 */
final class StoredEntryClassifier
{
    public const string ROLE = 'role';

    public const string CAPABILITY = 'capability';

    public const string DEAD = 'dead';

    /**
     * @param  array<string, array{name?: string, capabilities?: array<string, bool>}>  $roles  The roles WordPress has, by slug
     */
    public function classify(string $entry, array $roles): string
    {
        if (isset($roles[$entry])) {
            return self::ROLE;
        }

        foreach ($roles as $role) {
            if (array_key_exists($entry, $role['capabilities'] ?? [])) {
                return self::CAPABILITY;
            }
        }

        return self::DEAD;
    }
}
