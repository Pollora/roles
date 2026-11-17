<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures\Support\Facades;

use Pollora\Role\Application\Services\RoleDeclaration;
use Pollora\Role\Application\Services\RoleDeclarer;

/**
 * Stands in for the framework's facade base class: its path ends the way the real one's does,
 * so the declarer skips it when it looks for where the project called.
 */
final class Facade
{
    public static function make(RoleDeclarer $declarer, string $slug): RoleDeclaration
    {
        return $declarer->make($slug, 'Label');
    }
}
