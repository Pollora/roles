<?php

declare(strict_types=1);

// Minimal stand-ins for WordPress's role classes, with the properties the injector sets.

if (! class_exists('WP_Role')) {
    class WP_Role
    {
        public function __construct(public string $name, public array $capabilities) {}
    }
}

if (! class_exists('WP_Roles')) {
    class WP_Roles
    {
        public array $roles = [];

        public array $role_objects = [];

        public array $role_names = [];
    }
}
