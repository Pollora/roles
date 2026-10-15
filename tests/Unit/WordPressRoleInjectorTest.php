<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;
use Pollora\Role\Domain\Services\RoleCompiler;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleInjector;
use Pollora\Role\Tests\Fixtures\EventManager;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__).'/Fixtures/wordpress-roles.php';

function wpRolesWith(array $roles): WP_Roles
{
    $wpRoles = new WP_Roles;
    $wpRoles->roles = $roles;

    return $wpRoles;
}

beforeEach(function (): void {
    $this->registry = new RoleRegistry(new CapabilityOwnerReader, new PostTypeCapabilityMap);
    $this->logger = Mockery::mock(LoggerInterface::class);
    $this->injector = new WordPressRoleInjector($this->registry, new RoleCompiler, ['administrator'], $this->logger);
    $this->stored = [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true]],
        'author' => ['name' => 'Author', 'capabilities' => ['read' => true, 'publish_posts' => true]],
    ];
});

afterEach(function (): void {
    unset($GLOBALS['wp_roles']);
});

it('puts the compiled roles, role objects and names into WP_Roles', function (): void {
    $this->registry->addRole((new RoleDefinitionBuilder(new CapabilityOwnerReader))->build(EventManager::class));
    $wpRoles = wpRolesWith($this->stored);

    $this->injector->inject($wpRoles);

    expect($wpRoles->roles)->toHaveKeys(['administrator', 'author', 'event_manager'])
        ->and($wpRoles->role_names['event_manager'])->toBe('Event manager')
        ->and($wpRoles->role_objects['event_manager'])->toBeInstanceOf(WP_Role::class)
        ->and($wpRoles->role_objects['event_manager']->capabilities)->toHaveKey('export_attendees')
        ->and($wpRoles->role_objects['event_manager']->capabilities)->not->toHaveKey('publish_posts');
});

it('leaves WP_Roles alone when the project declares nothing', function (): void {
    $wpRoles = wpRolesWith($this->stored);

    $this->injector->inject($wpRoles);

    expect($wpRoles->roles)->toBe($this->stored)
        ->and($wpRoles->role_objects)->toBe([]);
});

it('removes what an earlier version of the code left in the database, even when nothing is declared now', function (): void {
    $wpRoles = wpRolesWith([...$this->stored, 'gone' => ['name' => 'Gone', 'capabilities' => [], '_pollora' => ['managed' => true]]]);

    $this->injector->inject($wpRoles);

    expect($wpRoles->roles)->toBe($this->stored);
});

it('injects into the existing WP_Roles and recomputes the current user capabilities on refresh', function (): void {
    $this->registry->addCapabilitySet(['scan_tickets']);
    $GLOBALS['wp_roles'] = wpRolesWith($this->stored);
    $user = Mockery::mock();
    $user->shouldReceive('exists')->andReturn(true);
    $user->shouldReceive('get_role_caps')->once();
    Functions\when('did_action')->justReturn(1);
    Functions\when('wp_get_current_user')->justReturn($user);

    $this->injector->refresh();

    expect($GLOBALS['wp_roles']->roles['administrator']['capabilities'])->toHaveKey('scan_tickets');
});

it('does not touch a current user that is not logged in', function (): void {
    $GLOBALS['wp_roles'] = wpRolesWith($this->stored);
    $user = Mockery::mock();
    $user->shouldReceive('exists')->andReturn(false);
    $user->shouldNotReceive('get_role_caps');
    Functions\when('did_action')->justReturn(1);
    Functions\when('wp_get_current_user')->justReturn($user);

    $this->injector->refresh();
});

it('waits for the current user before recomputing capabilities', function (): void {
    $GLOBALS['wp_roles'] = wpRolesWith($this->stored);
    Functions\when('did_action')->justReturn(0);
    Functions\expect('wp_get_current_user')->never();

    $this->injector->refresh();
});

it('does nothing on refresh before WordPress has its roles', function (): void {
    Functions\expect('did_action')->never();

    $this->injector->refresh();
});

it('logs each warning of the last compilation once', function (): void {
    $this->registry->addRole((new RoleDefinitionBuilder(new CapabilityOwnerReader))->build(EventManager::class));
    $this->injector->inject(wpRolesWith($this->stored));
    $this->injector->inject(wpRolesWith($this->stored));
    $this->logger->shouldReceive('warning')->once()->with('Roles: Pollora\Role\Tests\Fixtures\EventManager: the post type "venue" was not found with its own capabilities; its grant is ignored.');

    $this->injector->reportWarnings();
});
