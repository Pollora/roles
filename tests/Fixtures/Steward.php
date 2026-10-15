<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\Role;
use Pollora\Attributes\Role\Grants;

#[Role('steward', inherits: EventManager::class, allowSensitive: true)]
#[Grants('manage_options')]
final class Steward {}
