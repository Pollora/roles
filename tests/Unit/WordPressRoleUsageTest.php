<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleUsage;

beforeEach(function (): void {
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
    $this->queries = [];

    // Only what the adapter uses: the usermeta table, the blog prefix, prepare() and get_results()
    $GLOBALS['wpdb'] = new class($this)
    {
        public string $usermeta = 'wp_usermeta';

        public function __construct(private readonly object $test) {}

        public function get_blog_prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$args): string
        {
            $this->test->queries[] = [$query, $args];

            return $query;
        }

        public function get_results(string $query): array
        {
            return [
                (object) ['user_id' => '1', 'meta_value' => serialize(['administrator' => true])],
                (object) ['user_id' => '2', 'meta_value' => serialize(['event_manager' => true, 'old_cap' => false])],
                (object) ['user_id' => '3', 'meta_value' => serialize(['event_manager' => true, 'moderate_comments' => true])],
                (object) ['user_id' => '4', 'meta_value' => 'O:8:"stdClass":0:{}'],
            ];
        }
    };

    Functions\when('is_serialized')->alias(fn (string $value): bool => preg_match('/^[aO]:/', $value) === 1);
    Functions\when('get_userdata')->alias(fn (int $id): false => false);
});

afterEach(function (): void {
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

it('counts the granted entries users carry, read once from the current site', function (): void {
    $usage = new WordPressRoleUsage;

    expect($usage->entries())->toBe(['administrator' => 1, 'event_manager' => 2, 'moderate_comments' => 1])
        ->and($usage->usersWith('event_manager'))->toBe([2 => '#2', 3 => '#3'])
        ->and($this->queries)->toHaveCount(1)
        ->and($this->queries[0][1])->toBe(['wp_capabilities']);
});
