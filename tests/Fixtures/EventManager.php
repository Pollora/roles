<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\Role;
use Pollora\Attributes\Role\Grants;
use Pollora\Attributes\Role\GrantsPostType;
use Pollora\Attributes\Role\GrantsTaxonomy;
use Pollora\Attributes\Role\Without;
use Pollora\Role\Domain\Enums\Access;

#[Role('event_manager', label: 'Event manager', inherits: 'author')]
#[GrantsPostType(Event::class, Access::Editor)]
#[GrantsPostType('venue', Access::Author)]
#[GrantsTaxonomy(Genre::class)]
#[Grants(EventCap::ExportAttendees, 'scan_tickets')]
#[Grants('upload_files')]
#[Without('publish_posts')]
final class EventManager {}
