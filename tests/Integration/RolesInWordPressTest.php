<?php

declare(strict_types=1);

use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleStore;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleUsage;
use Pollora\Role\Role;
use Pollora\Role\Tests\Fixtures\Event;

/**
 * The roles package against a real WordPress, where the unit suite has a fake `WP_Roles`: the injection on
 * `wp_roles_init`, the capabilities WordPress then computes, the option it persists, the users it stores.
 *
 * Everything a test changes is put back: the hooks, the `wp_roles` global, the stored roles, the users it creates.
 */
beforeEach(function (): void {
    $GLOBALS['wpint'] = [
        // WP_Hook objects are changed in place: the snapshot needs copies of them.
        'hooks' => array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wp_filter']),
        'roles' => $GLOBALS['wp_roles'],
        'stored' => get_option('wp_user_roles'),
        'users' => [],
    ];

    // The cheapest bcrypt cost: a test user does not need a strong hash, and the default one takes a second.
    add_filter('wp_hash_password_options', static fn (array $options): array => ['cost' => 4] + $options);

    // The package registers its listeners on `wp_roles_init` the first time something is declared.
    remove_all_actions('wp_roles_init');
    Role::reset();
});

afterEach(function (): void {
    require_once ABSPATH.'wp-admin/includes/user.php';

    foreach ($GLOBALS['wpint']['users'] as $id) {
        wp_delete_user($id);
    }

    update_option('wp_user_roles', $GLOBALS['wpint']['stored']);
    $GLOBALS['wp_roles'] = $GLOBALS['wpint']['roles'];
    $GLOBALS['wp_filter'] = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wpint']['hooks']);
    Role::reset();
});

/**
 * Creates a user, removed after the test.
 */
function wpintUser(string $role, string $login): int
{
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.com', 'role' => $role]);
    expect($id)->toBeInt();
    $GLOBALS['wpint']['users'][] = $id;

    return $id;
}

it('hands a declared role to the WP_Roles WordPress builds, and the capabilities WordPress computes follow', function (): void {
    Role::make('wpint_manager', 'Integration manager')
        ->inherits('author')
        ->grants('wpint_export')
        ->without('publish_posts');

    $GLOBALS['wp_roles'] = new WP_Roles;
    $roles = wp_roles();
    $userId = wpintUser('wpint_manager', 'wpint_manager_user');

    expect($roles->is_role('wpint_manager'))->toBeTrue()
        ->and($roles->get_names()['wpint_manager'])->toBe('Integration manager')
        ->and($roles->get_role('wpint_manager')->has_cap('upload_files'))->toBeTrue()
        ->and($roles->get_role('wpint_manager')->has_cap('publish_posts'))->toBeFalse()
        ->and(user_can($userId, 'wpint_export'))->toBeTrue()
        ->and(user_can($userId, 'upload_files'))->toBeTrue()
        ->and(user_can($userId, 'publish_posts'))->toBeFalse()
        ->and(user_can($userId, 'manage_options'))->toBeFalse();
});

it('adjusts a core role through WordPress, and gives the declared capability to the administrator', function (): void {
    Role::capabilitySet(['wpint_audit']);
    Role::modify('editor')->without('moderate_comments')->grants('wpint_audit');

    $roles = new WP_Roles;

    expect($roles->get_role('editor')->has_cap('moderate_comments'))->toBeFalse()
        ->and($roles->get_role('editor')->has_cap('edit_others_posts'))->toBeTrue()
        ->and($roles->get_role('administrator')->has_cap('wpint_audit'))->toBeTrue()
        ->and($roles->get_role('subscriber')->has_cap('wpint_audit'))->toBeFalse();
});

it('keeps a declaration made after WordPress built its roles for the next load, as documented', function (): void {
    $built = new WP_Roles;
    $GLOBALS['wp_roles'] = $built;

    // `init` and `wp_loaded` are over: nothing is scheduled, the roles already built are left alone.
    Role::make('wpint_late', 'Late role')->inherits('subscriber');

    expect($built->is_role('wpint_late'))->toBeFalse()
        ->and((new WP_Roles)->is_role('wpint_late'))->toBeTrue();
});

it('writes nothing to the user_roles option until WordPress saves it, then marks the declared roles so a removed declaration is cleaned', function (): void {
    Role::make('wpint_marked', 'Marked role')->inherits('subscriber');

    $roles = new WP_Roles;

    expect(get_option('wp_user_roles'))->not->toHaveKey('wpint_marked');

    // Any add_role() persists the roles of the WP_Roles object, declared ones included.
    $roles->add_role('wpint_probe', 'Probe', ['read' => true]);

    expect(get_option('wp_user_roles'))->toHaveKey('wpint_marked')
        ->and(get_option('wp_user_roles')['wpint_marked']['_pollora'])->toBe(['managed' => true]);

    // The declaration is gone from the code (a fresh registry): the next load removes the role WordPress stored.
    remove_all_actions('wp_roles_init');
    Role::reset();
    Role::capabilitySet(['wpint_unrelated']);

    expect((new WP_Roles)->is_role('wpint_marked'))->toBeFalse();
});

it('translates a declared role label with its own text domain through the real gettext filters', function (): void {
    Role::make('wpint_translated', 'Integration steward')->inherits('subscriber')->textDomain('wpint-domain');
    // The package only learns the domain when the roles are built.
    new WP_Roles;

    add_filter('gettext_wpint-domain', static fn (string $translation, string $text): string => $text === 'Integration steward' ? 'Intendant' : $translation, 10, 2);

    expect(translate_user_role('Integration steward'))->toBe('Intendant')
        ->and(translate_user_role('Editor'))->toBe('Editor');
});

it('reads and writes users and stored roles through the real WordPress API and database', function (): void {
    $store = new WordPressRoleStore;
    $userId = wpintUser('subscriber', 'wpint_store_user');

    expect($store->rolesOf($userId))->toBe(['subscriber'])
        ->and($store->storedRoles())->toHaveKey('administrator');

    $store->addRoleToUser($userId, 'editor');
    expect($store->rolesOf($userId))->toBe(['subscriber', 'editor']);

    $store->removeFromUser($userId, 'editor');
    expect($store->rolesOf($userId))->toBe(['subscriber']);

    $stored = $store->storedRoles();
    $stored['wpint_saved'] = ['name' => 'Saved', 'capabilities' => ['read' => true]];
    $store->saveStoredRoles($stored);

    expect($store->storedRoles())->toHaveKey('wpint_saved')
        ->and($store->rolesOf(0))->toBe([]);
});

it('counts what users carry with one query on the real usermeta table, including a role WordPress no longer has', function (): void {
    $userId = wpintUser('subscriber', 'wpint_usage_user');
    // A role that exists in no code any more, still carried by the user.
    (new WP_User($userId))->add_cap('wpint_gone_role');

    $usage = new WordPressRoleUsage;

    expect($usage->entries())->toHaveKey('subscriber')
        ->and($usage->entries()['wpint_gone_role'])->toBe(1)
        ->and($usage->usersWith('wpint_gone_role'))->toBe([$userId => 'wpint_usage_user']);
});

it('names post type capabilities as get_post_type_capabilities() does', function (Access $access, array $overrides): void {
    $wordpress = get_post_type_capabilities((object) [
        'capability_type' => ['event', 'events'],
        'map_meta_cap' => true,
        'capabilities' => $overrides,
    ]);

    $expected = array_map(static fn (string $generic): string => $wordpress->{$generic}, $access->genericCapabilities());

    expect((new PostTypeCapabilityMap)->capabilities('event', $access, $overrides))->toBe($expected);
})->with([
    'contributor' => [Access::Contributor, []],
    'author' => [Access::Author, []],
    'editor' => [Access::Editor, []],
    'editor with an override' => [Access::Editor, ['publish_posts' => 'open_events', 'read_private_posts' => 'see_secret_events']],
]);

it('lets a role granted a post type edit the posts of other authors, and no one else', function (): void {
    register_post_type('event', ['capability_type' => 'event', 'map_meta_cap' => true, 'public' => false]);
    Role::make('wpint_events', 'Events editor')->inherits('subscriber')->grantsPostType(Event::class, Access::Editor);

    $GLOBALS['wp_roles'] = new WP_Roles;
    $owner = wpintUser('subscriber', 'wpint_event_owner');
    $editor = wpintUser('wpint_events', 'wpint_event_editor');
    $stranger = wpintUser('subscriber', 'wpint_event_stranger');
    $postId = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Gig', 'post_author' => $owner]);

    try {
        expect(user_can($editor, 'edit_post', $postId))->toBeTrue()
            ->and(user_can($editor, 'delete_post', $postId))->toBeTrue()
            ->and(user_can($stranger, 'edit_post', $postId))->toBeFalse()
            ->and(user_can($editor, 'edit_others_posts'))->toBeFalse();
    } finally {
        wp_delete_post($postId, true);
        unregister_post_type('event');
    }
});
