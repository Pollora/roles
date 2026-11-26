<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Models\RoleChanges;
use Pollora\Role\Domain\Models\RoleModification;
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

it('keeps every key and value of a stored entry through two compilations', function (): void {
    $this->registry->addCapabilitySet(['scan_tickets']);
    $this->registry->addModification(new RoleModification('shop', new RoleChanges(['export'], ['upload_files']), 'Class\\ModifyShop'));

    $shop = ['name' => 'Shop', 'capabilities' => ['read' => 1, 'upload_files' => 1, 'edit_posts' => 0], 'plugin_data' => ['color' => 'red']];
    $wpRoles = wpRolesWith([...$this->stored, 'shop' => $shop]);
    $injector = new WordPressRoleInjector($this->registry, new RoleCompiler, ['shop']);

    $injector->inject($wpRoles);
    // What WordPress saves on the next add_cap() is read again by the next request
    $injector->inject($wpRoles);

    expect($wpRoles->roles['shop'])->toBe([
        'name' => 'Shop',
        'capabilities' => ['read' => 1, 'edit_posts' => 0, 'export' => true, 'scan_tickets' => true],
        'plugin_data' => ['color' => 'red'],
        '_pollora' => ['granted' => ['export', 'scan_tickets'], 'removed' => ['upload_files' => 1]],
    ])
        ->and($wpRoles->role_objects['shop']->capabilities)->toBe(['read' => true, 'edit_posts' => false, 'export' => true, 'scan_tickets' => true]);
});

it('gives back the original entry once the code no longer changes it', function (): void {
    $shop = ['name' => 'Shop', 'capabilities' => ['read' => 1, 'upload_files' => 1], 'plugin_data' => ['color' => 'red']];
    $wpRoles = wpRolesWith(['shop' => [
        ...$shop,
        'capabilities' => ['read' => 1, 'export' => true],
        '_pollora' => ['removed' => ['upload_files' => 1], 'granted' => ['export']],
    ]]);

    $this->injector->inject($wpRoles);

    expect($wpRoles->roles['shop'])->toBe($shop);
});

it('does not take over a capability the role stores as 1, so dropping the grant keeps it', function (): void {
    $shop = ['name' => 'Shop', 'capabilities' => ['read' => 1]];
    $this->registry->addModification(new RoleModification('shop', new RoleChanges(['read'], []), 'Class\\ModifyShop'));
    $wpRoles = wpRolesWith(['shop' => $shop]);
    $this->injector->inject($wpRoles);
    $saved = $wpRoles->roles;
    $nothingDeclared = new WordPressRoleInjector(new RoleRegistry(new CapabilityOwnerReader, new PostTypeCapabilityMap), new RoleCompiler);
    $next = wpRolesWith($saved);

    $nothingDeclared->inject($next);

    expect($saved['shop'])->toBe($shop)
        ->and($next->roles['shop'])->toBe($shop);
});

it('registers an entry without a name or capabilities, leaving the entry as stored', function (): void {
    $this->registry->addRole((new RoleDefinitionBuilder(new CapabilityOwnerReader))->build(EventManager::class));
    $wpRoles = wpRolesWith([
        ...$this->stored,
        'legacy' => ['capabilities' => ['read' => 1]],
        'bare' => ['name' => 'Bare'],
        'broken' => 'not a role',
    ]);

    $this->injector->inject($wpRoles);

    expect($wpRoles->roles['legacy'])->toBe(['capabilities' => ['read' => 1]])
        ->and($wpRoles->roles['bare'])->toBe(['name' => 'Bare'])
        ->and($wpRoles->roles['broken'])->toBe('not a role')
        ->and($wpRoles->role_names['legacy'])->toBe('legacy')
        ->and($wpRoles->role_names['bare'])->toBe('Bare')
        ->and($wpRoles->role_names['broken'])->toBe('broken')
        ->and($wpRoles->role_objects['legacy']->capabilities)->toBe(['read' => true])
        ->and($wpRoles->role_objects['bare']->capabilities)->toBe([])
        ->and($wpRoles->role_objects['broken']->capabilities)->toBe([]);
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
