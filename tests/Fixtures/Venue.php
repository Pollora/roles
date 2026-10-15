<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\PostType;
use Pollora\Attributes\PostType\Capabilities;
use Pollora\Attributes\PostType\CapabilityType;

#[PostType('venue')]
#[CapabilityType('venue')]
#[Capabilities(['publish_posts' => 'open_venues'])]
class Venue {}
