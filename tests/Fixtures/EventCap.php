<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\CapabilitySet;

#[CapabilitySet(label: 'Events')]
enum EventCap: string
{
    case ExportAttendees = 'export_attendees';
    case ScanTickets = 'scan_tickets';
}
