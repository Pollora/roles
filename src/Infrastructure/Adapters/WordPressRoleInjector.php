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

        foreach ($this->registry->refusals() as $refusal) {
            $warn($refusal);
        }

        $roles = $this->compiler->compile(
            $this->storedRoles($wpRoles->roles),
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
     * The roles WordPress holds, in the shape the compiler works with. WordPress types them loosely (`array[]`):
     * a role without a name takes its slug, a capability is granted when its value is truthy (a plugin may store 1
     * for true), and the marker Pollora keeps is carried over.
     *
     * @param  array<array-key, mixed>  $roles
     * @return array<string, array{name: string, capabilities: array<string, bool>, _pollora?: array{managed?: bool, granted?: list<string>, removed?: array<string, bool>}}>
     */
    private function storedRoles(array $roles): array
    {
        $stored = [];

        foreach ($roles as $slug => $role) {
            $role = is_array($role) ? $role : [];
            $capabilities = [];

            foreach (is_array($role['capabilities'] ?? null) ? $role['capabilities'] : [] as $capability => $granted) {
                $capabilities[(string) $capability] = (bool) $granted;
            }

            $entry = [
                'name' => is_string($role['name'] ?? null) ? $role['name'] : (string) $slug,
                'capabilities' => $capabilities,
            ];

            $marker = $this->markerOf($role[RoleCompiler::MARKER] ?? null);

            if ($marker !== null) {
                $entry[RoleCompiler::MARKER] = $marker;
            }

            $stored[(string) $slug] = $entry;
        }

        return $stored;
    }

    /**
     * The marker Pollora keeps in a role entry, as it wrote it: what is not of the expected type is dropped.
     *
     * @return array{managed?: bool, granted?: list<string>, removed?: array<string, bool>}|null Null when there is no marker
     */
    private function markerOf(mixed $marker): ?array
    {
        if (! is_array($marker)) {
            return null;
        }

        $normalized = [];

        if (($marker['managed'] ?? false) === true) {
            $normalized['managed'] = true;
        }

        $granted = array_values(array_filter(is_array($marker['granted'] ?? null) ? $marker['granted'] : [], is_string(...)));

        if ($granted !== []) {
            $normalized['granted'] = $granted;
        }

        $removed = [];

        foreach (is_array($marker['removed'] ?? null) ? $marker['removed'] : [] as $capability => $value) {
            $removed[(string) $capability] = (bool) $value;
        }

        if ($removed !== []) {
            $normalized['removed'] = $removed;
        }

        return $normalized;
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
