<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\ModifyRole;
use Pollora\Attributes\Role\Grants;
use Pollora\Attributes\Role\GrantsPostType;
use Pollora\Attributes\Role\Without;
use Pollora\Role\Domain\Enums\Access;

#[ModifyRole('editor')]
#[GrantsPostType(Event::class, Access::Contributor)]
#[Grants(EventCap::ScanTickets)]
#[Without('edit_theme_options')]
final class EditorAdjustments {}
