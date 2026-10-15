<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleStore;

require_once dirname(__DIR__).'/Fixtures/wordpress-roles.php';

beforeEach(function (): void {
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new class
    {
        public function get_blog_prefix(): string
        {
            return 'wp_2_';
        }
    };
});

afterEach(function (): void {
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

it('reads and writes the roles option of the current site', function (): void {
    $calls = [];
    Functions\when('get_option')->alias(function (string $name) use (&$calls): array {
        $calls[] = 'get '.$name;

        return ['editor' => ['name' => 'Editor', 'capabilities' => []]];
    });
    Functions\when('update_option')->alias(function (string $name, array $value) use (&$calls): bool {
        $calls[] = 'update '.$name.': '.implode(', ', array_keys($value));

        return true;
    });

    $store = new WordPressRoleStore;
    $stored = $store->storedRoles();
    $store->saveStoredRoles(['author' => ['name' => 'Author', 'capabilities' => []]]);

    expect($stored)->toHaveKey('editor')
        ->and($calls)->toBe(['get wp_2_user_roles', 'update wp_2_user_roles: author']);
});

it('takes a role WordPress no longer knows off a user through remove_cap()', function (): void {
    $user = Mockery::mock('WP_User');
    $user->shouldReceive('remove_cap')->once()->with('event_manager');
    $user->shouldReceive('add_role')->once()->with('subscriber');
    Functions\when('get_userdata')->justReturn($user);

    $store = new WordPressRoleStore;
    $store->removeFromUser(3, 'event_manager');
    $store->addRoleToUser(3, 'subscriber');
});
