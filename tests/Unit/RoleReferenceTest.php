<?php

declare(strict_types=1);

use Pollora\Role\Application\Services\RoleReference;
use Pollora\Role\Tests\Fixtures\EditorAdjustments;
use Pollora\Role\Tests\Fixtures\EventManager;
use Pollora\Role\Tests\Fixtures\Venue;

it('keeps a slug as it is', function (): void {
    expect(RoleReference::slug('editor'))->toBe('editor');
});

it('reads the slug of a #[Role] or #[ModifyRole] class', function (): void {
    expect(RoleReference::slug(EventManager::class))->toBe('event_manager')
        ->and(RoleReference::slug(EditorAdjustments::class))->toBe('editor');
});

it('refuses a class that names no role', function (): void {
    RoleReference::slug(Venue::class);
})->throws(InvalidArgumentException::class, Venue::class.' does not name a role');

it('matches when the user has one of the roles, ignoring case', function (): void {
    expect(RoleReference::matchesAny(['author', 'event_manager'], ['editor', EventManager::class]))->toBeTrue()
        ->and(RoleReference::matchesAny(['Editor'], ['EDITOR']))->toBeTrue()
        ->and(RoleReference::matchesAny(['author'], ['editor', EventManager::class]))->toBeFalse()
        ->and(RoleReference::matchesAny([], ['editor']))->toBeFalse()
        ->and(RoleReference::matchesAny(['editor'], []))->toBeFalse();
});
