<?php

declare(strict_types=1);

namespace Pollora\Role\Infrastructure\Adapters;

use WP_Roles;

/**
 * Hands a role declared after WordPress built `WP_Roles` to it, once, early on `init`.
 *
 * A theme's `functions.php` declares its roles then. One declared after
 * `wp_loaded` waits for the next load.
 */
final class WordPressLateRefresh
{
    private bool $scheduled = false;

    public function __construct(private readonly WordPressRoleInjector $injector) {}

    public function __invoke(): void
    {
        if ($this->scheduled || ! ($GLOBALS['wp_roles'] ?? null) instanceof WP_Roles) {
            return;
        }

        $hook = match (true) {
            \did_action('init') === 0 => 'init',
            \did_action('wp_loaded') === 0 => 'wp_loaded',
            default => null,
        };

        if ($hook === null) {
            return;
        }

        $this->scheduled = true;
        \add_action($hook, function (): void {
            $this->scheduled = false;
            $this->injector->refresh();
        }, 0);
    }
}
