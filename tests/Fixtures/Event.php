<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\PostType;
use Pollora\Attributes\PostType\CapabilityType;
use Pollora\Attributes\PostType\MapMetaCap;

#[PostType('event')]
#[CapabilityType('event')]
#[MapMetaCap]
class Event {}
