<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDeclaration;
use Pollora\Role\Application\Services\RoleDeclarer;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;
use Pollora\Role\Role;
use Pollora\Role\Tests\Fixtures\Event;
use Pollora\Role\Tests\Fixtures\EventCap;

require_once dirname(__DIR__).'/Fixtures/wordpress-roles.php';

beforeEach(function (): void {
    Role::reset();
    unset($GLOBALS['wp_roles']);
    $this->stored = [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true]],
        'editor' => ['name' => 'Editor', 'capabilities' => ['edit_posts' => true, 'edit_theme_options' => true]],
        'author' => ['name' => 'Author', 'capabilities' => ['read' => true, 'edit_posts' => true, 'publish_posts' => true, 'upload_files' => true]],
    ];
});

afterEach(function (): void {
    Role::reset();
    unset($GLOBALS['wp_roles']);
});

/**
 * @param  array<string, array<string, mixed>>  $roles
 */
function wpRolesFrom(array $roles): WP_Roles
{
    $wpRoles = new WP_Roles;
    $wpRoles->roles = $roles;

    return $wpRoles;
}

it('hooks itself on WordPress the first time it is used, and no more', function (): void {
    Actions\expectAdded('wp_roles_init')->once();
    Actions\expectAdded('init')->once();

    Role::make('usher', 'Usher');
    Role::make('steward', 'Steward');
    Role::manager();
});

it('gives the roles to WordPress when it builds WP_Roles', function (): void {
    Role::make('event_manager', 'Event manager')
        ->inherits('author')
        ->grantsPostType(Event::class, Access::Editor)
        ->grants(EventCap::ExportAttendees)
        ->without('publish_posts');
    Role::modify('editor')->without('edit_theme_options');
    Role::manager()->addPostType(Event::class);

    $wpRoles = wpRolesFrom($this->stored);
    $injector = (new ReflectionProperty(Role::class, 'injector'))->getValue();
    $injector->inject($wpRoles);

    expect($wpRoles->roles['event_manager']['capabilities'])->toHaveKeys(['read', 'export_attendees', 'edit_others_events'])
        ->and($wpRoles->roles['event_manager']['capabilities'])->not->toHaveKey('publish_posts')
        ->and($wpRoles->role_names['event_manager'])->toBe('Event manager')
        ->and($wpRoles->roles['editor']['capabilities'])->not->toHaveKey('edit_theme_options');
});

it('is empty until something is declared, and not once a declaration waits', function (): void {
    expect(Role::manager()->isEmpty())->toBeTrue();

    Role::make('usher', 'Usher');

    expect(Role::manager()->isEmpty())->toBeFalse();
});

it('keeps a refused declaration out of the roles and says why when WordPress builds them', function (): void {
    Role::make('meddler', 'Meddler')->grants('edit_users');
    Role::make('usher', 'Usher')->inherits('subscriber');

    $wpRoles = wpRolesFrom($this->stored);
    $injector = (new ReflectionProperty(Role::class, 'injector'))->getValue();
    $injector->inject($wpRoles);

    expect($wpRoles->roles)->not->toHaveKey('meddler')
        ->and($wpRoles->roles)->toHaveKey('usher')
        ->and($injector->warnings()[0])->toContain("Role::make('meddler')")
        ->and($injector->warnings()[0])->toContain('allowSensitive: true');
});

it('names the call that declared a role in what it refuses', function (): void {
    Role::make('editor', 'Editor');

    expect(Role::manager()->refusals()[0])->toStartWith("Role::make('editor') at ".__FILE__.':');
});

it('refuses a second declaration of the same role, naming both', function (): void {
    Role::make('usher', 'Usher');
    Role::make('usher', 'Usher again');

    expect(Role::manager()->refusals())->toHaveCount(1)
        ->and(Role::manager()->refusals()[0])->toContain("the role \"usher\" is already declared by Role::make('usher') at ".__FILE__);
});

it('tells apart two modifications made on the same line', function (): void {
    foreach (['editor', 'author'] as $slug) {
        Role::modify($slug)->grants('moderate_comments');
    }

    expect(Role::manager()->modifications())->toHaveCount(2);
});

it('has no label to inherit or translate on a role that is modified', function (): void {
    expect(fn () => Role::modify('editor')->inherits('author'))->toThrow(LogicException::class, 'does not apply to a role that is modified')
        ->and(fn () => Role::modify('editor')->textDomain('events'))->toThrow(LogicException::class);
});

it('refuses a fluent declaration in a registry that cannot check it', function (): void {
    $registry = new RoleRegistry(new CapabilityOwnerReader, new PostTypeCapabilityMap);

    expect(fn () => $registry->declare(new RoleDeclaration('usher', 'Usher', false, 'test')))->toThrow(LogicException::class);
});

it('injects a role declared after WordPress built its roles, once, on init', function (): void {
    $GLOBALS['wp_roles'] = wpRolesFrom($this->stored);
    Functions\when('did_action')->justReturn(0);
    $scheduled = [];
    Functions\when('add_action')->alias(function (string $hook, $callback, int $priority = 10) use (&$scheduled): bool {
        $scheduled[] = [$hook, $priority];

        return true;
    });

    Role::make('usher', 'Usher')->inherits('author');
    Role::make('steward', 'Steward')->inherits('author');

    expect(array_filter($scheduled, static fn (array $hook): bool => $hook === ['init', 0]))->toHaveCount(1);
});

it('does not schedule a refresh while WordPress has no roles yet', function (): void {
    $scheduled = [];
    Functions\when('add_action')->alias(function (string $hook, $callback, int $priority = 10) use (&$scheduled): bool {
        $scheduled[] = [$hook, $priority];

        return true;
    });

    Role::make('usher', 'Usher');

    expect(array_filter($scheduled, static fn (array $hook): bool => $hook[1] === 0))->toBeEmpty();
});

it('declares through a RoleDeclarer on a registry of its own, and tells who listens after each declaration', function (): void {
    $owners = new CapabilityOwnerReader;
    $registry = new RoleRegistry($owners, new PostTypeCapabilityMap, new RoleDefinitionBuilder($owners));
    $calls = 0;
    $declarer = new RoleDeclarer($registry, function () use (&$calls): void {
        $calls++;
    });

    $declarer->make('usher', 'Usher')->inherits('author');
    $declarer->modify('editor')->grants('moderate_comments');
    $declarer->capabilitySet(EventCap::class);

    expect($calls)->toBe(3)
        ->and($registry->definitions()[0]->declaringClass)->toBe("Role::make('usher') at ".__FILE__.':'.(__LINE__ - 6))
        ->and($registry->modifications())->toHaveCount(1)
        ->and($registry->declaredCapabilities())->toBe(['export_attendees', 'scan_tickets']);
});
