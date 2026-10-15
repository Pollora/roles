<?php

declare(strict_types=1);

namespace Pollora\Role\Domain\Services;

use Closure;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Domain\Models\RoleModification;

/**
 * Builds the roles WordPress works with from the roles stored in the database
 * and the roles declared in code.
 *
 * A pure function of its inputs, run on every `wp_roles_init`:
 *  1. What Pollora added to the stored roles is undone first (a plugin calling
 *     `add_cap()` writes the in-memory roles back to the database, Pollora's
 *     included). Every entry Pollora touches carries a `_pollora` marker for
 *     that purpose, so a role or a capability removed from the code is gone,
 *     not frozen in the database.
 *  2. `#[ModifyRole]` changes are applied to the roles the project does not own.
 *  3. `#[Role]` roles are built: inherited capabilities, plus grants, minus
 *     removals. They replace any stored role with the same slug.
 *  4. Super roles receive every declared capability.
 *
 * Compiling an already compiled set of roles gives the same result.
 *
 * @phpstan-type StoredRole array{name: string, capabilities: array<string, bool>, _pollora?: array<string, mixed>}
 */
final class RoleCompiler
{
    /**
     * Key of the marker Pollora keeps in the role entries it touches.
     */
    public const string MARKER = '_pollora';

    /**
     * @param  array<string, StoredRole>  $storedRoles  `WP_Roles::$roles`
     * @param  list<RoleDefinition>  $definitions  Declared roles, with resolved grants
     * @param  list<RoleModification>  $modifications  Modifications, with resolved grants
     * @param  list<string>  $superRoles  Roles receiving every declared capability
     * @param  list<string>  $declaredCapabilities  Capabilities of post types, taxonomies and capability sets
     * @param  Closure(string): void|null  $warn  Receives what could not be applied
     * @return array<string, StoredRole>
     */
    public function compile(
        array $storedRoles,
        array $definitions,
        array $modifications,
        array $superRoles,
        array $declaredCapabilities,
        ?Closure $warn = null,
    ): array {
        $roles = $this->undo($storedRoles);

        $declared = [];
        foreach ($definitions as $definition) {
            $declared[$definition->slug] = $definition;
        }

        foreach ($modifications as $modification) {
            if (! isset($declared[$modification->slug])) {
                $this->modify($roles, $modification, $warn);
            }
        }

        $resolved = [];
        foreach ($declared as $slug => $definition) {
            $roles[$slug] = [
                'name' => $definition->label,
                'capabilities' => $this->capabilitiesOf($slug, $declared, $roles, $resolved, $warn),
                self::MARKER => ['managed' => true],
            ];
        }

        foreach ($modifications as $modification) {
            if (isset($declared[$modification->slug])) {
                $this->modify($roles, $modification, $warn);
            }
        }

        foreach ($superRoles as $superRole) {
            if (isset($roles[$superRole])) {
                $this->grant($roles[$superRole], $declaredCapabilities);
            }
        }

        return $roles;
    }

    /**
     * Removes what a previous compilation added: managed roles, granted capabilities,
     * removed capabilities (restored with their original value).
     *
     * @param  array<string, StoredRole>  $roles
     * @return array<string, StoredRole>
     */
    private function undo(array $roles): array
    {
        foreach ($roles as $slug => $role) {
            $marker = $role[self::MARKER] ?? null;

            if (! is_array($marker)) {
                continue;
            }

            if (($marker['managed'] ?? false) === true) {
                unset($roles[$slug]);

                continue;
            }

            foreach ($marker['granted'] ?? [] as $capability) {
                unset($role['capabilities'][$capability]);
            }

            foreach ($marker['removed'] ?? [] as $capability => $value) {
                $role['capabilities'][$capability] = $value;
            }

            unset($role[self::MARKER]);
            $roles[$slug] = $role;
        }

        return $roles;
    }

    /**
     * @param  array<string, RoleDefinition>  $declared
     * @param  array<string, StoredRole>  $roles
     * @param  array<string, array<string, bool>>  $resolved  Memo of capabilities already resolved
     * @param  Closure(string): void|null  $warn
     * @param  list<string>  $resolving  Slugs being resolved, to stop a cycle
     * @return array<string, bool>
     */
    private function capabilitiesOf(string $slug, array $declared, array $roles, array &$resolved, ?Closure $warn, array $resolving = []): array
    {
        if (isset($resolved[$slug])) {
            return $resolved[$slug];
        }

        $definition = $declared[$slug];
        $capabilities = [];

        if ($definition->inherits !== null) {
            if (in_array($definition->inherits, $resolving, true)) {
                $this->warn($warn, sprintf('The role "%s" inherits from itself through "%s"; inheritance ignored.', $slug, $definition->inherits));
            } elseif (isset($declared[$definition->inherits])) {
                $capabilities = $this->capabilitiesOf($definition->inherits, $declared, $roles, $resolved, $warn, [...$resolving, $slug]);
            } elseif (isset($roles[$definition->inherits])) {
                $capabilities = $roles[$definition->inherits]['capabilities'];
            } else {
                $this->warn($warn, sprintf('The role "%s" inherits from "%s", which does not exist; it starts with no capability.', $slug, $definition->inherits));
            }
        }

        foreach ($definition->changes->grants as $capability) {
            $capabilities[$capability] = true;
        }

        foreach ($definition->changes->removals as $capability) {
            unset($capabilities[$capability]);
        }

        return $resolved[$slug] = $capabilities;
    }

    /**
     * @param  array<string, StoredRole>  $roles
     * @param  Closure(string): void|null  $warn
     */
    private function modify(array &$roles, RoleModification $modification, ?Closure $warn): void
    {
        if (! isset($roles[$modification->slug])) {
            $this->warn($warn, sprintf('%s modifies the role "%s", which does not exist.', $modification->declaringClass, $modification->slug));

            return;
        }

        $role = &$roles[$modification->slug];
        $this->grant($role, $modification->changes->grants);

        foreach ($modification->changes->removals as $capability) {
            if (! array_key_exists($capability, $role['capabilities'])) {
                continue;
            }

            if (! in_array($capability, $role[self::MARKER]['granted'] ?? [], true)) {
                $role[self::MARKER]['removed'][$capability] = $role['capabilities'][$capability];
            }

            unset($role['capabilities'][$capability]);
        }
    }

    /**
     * Grants capabilities, remembering those the role did not already have.
     *
     * @param  StoredRole  $role
     * @param  list<string>  $capabilities
     */
    private function grant(array &$role, array $capabilities): void
    {
        $managed = ($role[self::MARKER]['managed'] ?? false) === true;

        foreach ($capabilities as $capability) {
            if (($role['capabilities'][$capability] ?? false) === true) {
                continue;
            }

            $role['capabilities'][$capability] = true;

            if (! $managed) {
                $role[self::MARKER]['granted'][] = $capability;
            }
        }
    }

    /**
     * @param  Closure(string): void|null  $warn
     */
    private function warn(?Closure $warn, string $message): void
    {
        if ($warn instanceof Closure) {
            $warn($message);
        }
    }
}
