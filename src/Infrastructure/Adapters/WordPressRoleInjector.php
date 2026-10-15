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

        $roles = $this->compiler->compile(
            $wpRoles->roles,
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
            $wpRoles->role_objects[$slug] = new WP_Role($slug, $role['capabilities']);
            $wpRoles->role_names[$slug] = $role['name'];
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
     * @param  array<string, array<string, mixed>>  $roles
     */
    private function hasMarkedRoles(array $roles): bool
    {
        foreach ($roles as $role) {
            if (isset($role[RoleCompiler::MARKER])) {
                return true;
            }
        }

        return false;
    }
}
