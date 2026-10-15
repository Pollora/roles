<?php

declare(strict_types=1);

namespace Pollora\Role\Infrastructure\Adapters;

use Pollora\Role\Domain\Contracts\RoleUsageInterface;

/**
 * Reads the `{prefix}capabilities` user meta of the current site in one query:
 * `count_users()` only counts the roles WordPress knows, so it cannot see a
 * role removed from the code that users still carry.
 */
final class WordPressRoleUsage implements RoleUsageInterface
{
    /** @var array<int, array<string, mixed>>|null Stored capabilities by user ID, read once */
    private ?array $capabilities = null;

    public function entries(): array
    {
        $entries = [];

        foreach ($this->capabilities() as $capabilities) {
            foreach ($capabilities as $name => $granted) {
                if ($granted) {
                    $entries[(string) $name] = ($entries[(string) $name] ?? 0) + 1;
                }
            }
        }

        ksort($entries);

        return $entries;
    }

    public function usersWith(string $entry): array
    {
        $users = [];

        foreach ($this->capabilities() as $userId => $capabilities) {
            if (! empty($capabilities[$entry])) {
                $user = \get_userdata($userId);
                $users[$userId] = $user instanceof \WP_User ? $user->user_login : '#'.$userId;
            }
        }

        return $users;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        /** @var \wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object{user_id: string, meta_value: string}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
            $wpdb->get_blog_prefix().'capabilities'
        ));

        $this->capabilities = [];

        foreach ($rows as $row) {
            $value = \is_serialized($row->meta_value) ? @unserialize(trim($row->meta_value), ['allowed_classes' => false]) : null;
            $this->capabilities[(int) $row->user_id] = is_array($value) ? $value : [];
        }

        return $this->capabilities;
    }
}
