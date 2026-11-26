<?php

declare(strict_types=1);

namespace Pollora\Role\Infrastructure\Adapters;

use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Services\RoleCompiler;
use Psr\Log\LoggerInterface;
use WP_Role;
use WP_Roles;

/**
 * Puts the compiled roles into WordPress's `WP_Roles`, in memory, without
 * writing the `user_roles` option.
 *
 * Subscribed to `wp_roles_init` before WordPress loads, so every `WP_Roles`
 * instance receives the declared roles: the one a plugin creates early by
 * calling `current_user_can()`, the one `wp-settings.php` creates, and each
 * one after a site switch in multisite. Roles declared in a plugin or a theme
 * are only known once its files are included, after WordPress has its roles
 * and often after the current user's capabilities are computed: refresh()
 * injects them into the existing instance and recomputes those capabilities.
 *
 * @phpstan-import-type StoredRole from RoleCompiler
 */
final class WordPressRoleInjector
{
    /**
     * @var list<string> What the last compilation could not apply
     */
    private array $warnings = [];

    /**
     * @param  list<string>  $superRoles
     */
    public function __construct(
        private readonly RoleRegistry $registry,
        private readonly RoleCompiler $compiler,
        private readonly array $superRoles = ['administrator'],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Compiles the roles of a `WP_Roles` instance and puts them in place. Hooked on `wp_roles_init`.
     */
    public function inject(WP_Roles $wpRoles): void
    {
        if ($this->registry->isEmpty() && ! $this->hasMarkedRoles($wpRoles->roles)) {
            return;
        }

        $this->warnings = [];
        $warn = function (string $message): void {
            $this->warnings[] = $message;
        };

        foreach ($this->registry->refusals() as $refusal) {
            $warn($refusal);
        }

        // The entries go to the compiler as WordPress holds them, never rebuilt: WordPress saves the whole array on the
        // next add_cap(), so a key or a value lost here would be lost from the database. WordPress types them `array[]`;
        // the shape asserted is the one its own WP_Roles relies on.
        /** @var array<string, StoredRole> $stored */
        $stored = $wpRoles->roles;

        $roles = $this->compiler->compile(
            $stored,
            $this->registry->definitions($warn),
            $this->registry->modifications($warn),
            $this->superRoles,
            $this->registry->declaredCapabilities(),
            $warn,
        );

        $wpRoles->roles = $roles;
        $wpRoles->role_objects = [];
        $wpRoles->role_names = [];

        foreach ($roles as $slug => $role) {
            $this->register($wpRoles, (string) $slug, $role);
        }
    }

    /**
     * Injects declarations added after WordPress built its roles, and recomputes
     * the current user's capabilities if they were already computed.
     */
    public function refresh(): void
    {
        $wpRoles = $GLOBALS['wp_roles'] ?? null;

        if (! $wpRoles instanceof WP_Roles) {
            return;
        }

        $this->inject($wpRoles);

        if (\did_action('set_current_user') > 0) {
            $user = \wp_get_current_user();

            if ($user->exists()) {
                $user->get_role_caps();
            }
        }
    }

    /**
     * What the last compilation could not apply (an unknown inherited role, a
     * modification of a role that does not exist…).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Logs what the last compilation could not apply. Hooked late, once every
     * location has been discovered.
     */
    public function reportWarnings(): void
    {
        foreach (array_unique($this->warnings) as $warning) {
            $this->logger?->warning('Roles: '.$warning);
        }
    }

    /**
     * Puts the role object and the name of one entry in place. `WP_Roles::$roles` keeps the entry as it is: what is
     * derived here (a name taken from the slug, capabilities read as booleans) is never saved.
     */
    private function register(WP_Roles $wpRoles, string $slug, mixed $role): void
    {
        $role = is_array($role) ? $role : [];
        $capabilities = is_array($role['capabilities'] ?? null) ? $role['capabilities'] : [];

        $wpRoles->role_objects[$slug] = new WP_Role($slug, array_map(boolval(...), $capabilities));
        $wpRoles->role_names[$slug] = is_string($role['name'] ?? null) ? $role['name'] : $slug;
    }

    /**
     * @param  array<array-key, mixed>  $roles
     */
    private function hasMarkedRoles(array $roles): bool
    {
        foreach ($roles as $role) {
            if (is_array($role) && isset($role[RoleCompiler::MARKER])) {
                return true;
            }
        }

        return false;
    }
}
