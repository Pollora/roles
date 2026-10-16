<?php

declare(strict_types=1);

namespace Pollora\Role;

use BackedEnum;
use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDeclaration;
use Pollora\Role\Application\Services\RoleDeclarer;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;
use Pollora\Role\Domain\Services\RoleCompiler;
use Pollora\Role\Infrastructure\Adapters\WordPressLateRefresh;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleInjector;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleLabelTranslator;
use Psr\Log\LoggerInterface;

/**
 * Declares roles and capabilities in code for a WordPress theme or plugin that is not a Pollora application: a classic theme, a plugin, a Bedrock site.
 * It is the framework's `Role` facade for a project with no Laravel: the same
 * calls, wired to WordPress. Nothing to register: a declaration is checked and
 * handed to WordPress when `WP_Roles` is built.
 *
 * ```php
 * Role::make('event_manager', 'Event manager')
 *     ->inherits('author')
 *     ->grantsPostType(Event::class, Access::Editor)
 *     ->grants('export_attendees')
 *     ->without('publish_posts');
 * ```
 *
 * A declaration made after WordPress built its roles (a theme's `functions.php`)
 * reaches them on `init`; one made after `wp_loaded` waits for the next load.
 */
final class Role
{
    private static ?RoleRegistry $registry = null;

    private static ?WordPressRoleInjector $injector = null;

    private static ?LoggerInterface $logger = null;

    private static ?RoleDeclarer $declarer = null;

    /**
     * Declares a role.
     */
    public static function make(string $slug, string $label): RoleDeclaration
    {
        return self::declarer()->make($slug, $label);
    }

    /**
     * Adjusts a role WordPress already has: a core role, or one a plugin adds.
     */
    public static function modify(string $slug): RoleDeclaration
    {
        return self::declarer()->modify($slug);
    }

    /**
     * Declares capabilities that belong to the project, so the super roles receive them.
     *
     * @param  class-string<BackedEnum>|list<string>  $capabilities  A backed enum of capabilities, or their names
     */
    public static function capabilitySet(string|array $capabilities): void
    {
        self::declarer()->capabilitySet($capabilities);
    }

    /**
     * The registry every declaration goes into, wired to WordPress.
     */
    public static function manager(): RoleRegistry
    {
        return self::$registry ??= self::build();
    }

    /**
     * Where what could not be applied is reported. Set it before the first call.
     */
    public static function useLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Forget everything: the registry and its declarations. For tests.
     */
    public static function reset(): void
    {
        self::$registry = null;
        self::$injector = null;
        self::$logger = null;
        self::$declarer = null;
    }

    private static function declarer(): RoleDeclarer
    {
        self::manager();

        return self::$declarer;
    }

    private static function build(): RoleRegistry
    {
        $superRoles = ['administrator'];
        $owners = new CapabilityOwnerReader;
        $registry = new RoleRegistry($owners, new PostTypeCapabilityMap, new RoleDefinitionBuilder($owners, $superRoles));
        self::$injector = new WordPressRoleInjector($registry, new RoleCompiler, $superRoles, self::$logger);

        // Early priority: listeners after it see the roles as the code declares them.
        \add_action('wp_roles_init', [self::$injector, 'inject'], 1);
        \add_action('init', [self::$injector, 'reportWarnings'], PHP_INT_MAX);
        \add_filter('gettext_with_context_default', [new WordPressRoleLabelTranslator($registry), 'translate'], 10, 3);

        self::$declarer = new RoleDeclarer($registry, (new WordPressLateRefresh(self::$injector))->__invoke(...));

        return $registry;
    }
}
