<?php

declare(strict_types=1);

// pollora/roles runs with or without Laravel: nothing in it may reach Illuminate or the framework.
arch('the package does not use Laravel')
    ->expect('Pollora\Role')
    ->not->toUse(['Illuminate', 'Laravel', 'Pollora\Support', 'Pollora\Models', 'Pollora\Doctor']);

arch('the domain stays free of Discovery')
    ->expect('Pollora\Role\Domain')
    ->not->toUse(['Pollora\Discovery', 'Psr\Log']);
