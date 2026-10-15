<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\Taxonomy;
use Pollora\Attributes\Taxonomy\Capabilities;

#[Taxonomy('genre')]
#[Capabilities(['manage_terms' => 'manage_genres', 'edit_terms' => 'edit_genres', 'assign_terms' => 'assign_genres', 'other' => 'ignored'])]
class Genre {}
