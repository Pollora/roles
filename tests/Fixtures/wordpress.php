<?php

declare(strict_types=1);

// The few WordPress classes the tests build, which no WordPress is here to define.

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string|int $code = '', public string $message = '', public mixed $data = '') {}
    }
}
