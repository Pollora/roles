<?php

declare(strict_types=1);

use Pollora\Role\Domain\Models\RoleChanges;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Domain\Models\RoleModification;
use Pollora\Role\Domain\Services\RoleCompiler;

function storedRoles(): array
{
    return [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true, 'edit_posts' => true]],
        'editor' => ['name' => 'Editor', 'capabilities' => ['edit_posts' => true, 'edit_theme_options' => true, 'moderate_comments' => true]],
        'author' => ['name' => 'Author', 'capabilities' => ['read' => true, 'edit_posts' => true, 'publish_posts' => true, 'upload_files' => true]],
        'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true]],
    ];
}

function role(string $slug, ?string $inherits = null, array $grants = [], array $removals = [], string $label = 'Label'): RoleDefinition
{
    return new RoleDefinition($slug, $label, $inherits, new RoleChanges($grants, $removals), 'Class\\'.$slug);
}

function modification(string $slug, array $grants = [], array $removals = []): RoleModification
{
    return new RoleModification($slug, new RoleChanges($grants, $removals), 'Class\\Modify'.ucfirst($slug));
}

beforeEach(function (): void {
    $this->compiler = new RoleCompiler;
});

it('builds a declared role from its parent, plus grants, minus removals, and marks it', function (): void {
    $roles = $this->compiler->compile(storedRoles(), [role('event_manager', 'author', ['export_attendees'], ['publish_posts'], 'Event manager')], [], [], []);

    expect($roles['event_manager'])->toBe([
        'name' => 'Event manager',
        'capabilities' => ['read' => true, 'edit_posts' => true, 'upload_files' => true, 'export_attendees' => true],
        '_pollora' => ['managed' => true],
    ]);
});

it('inherits from another declared role, whatever the declaration order', function (): void {
    $roles = $this->compiler->compile(storedRoles(), [
        role('steward', 'event_manager', ['scan_tickets']),
        role('event_manager', 'subscriber', ['export_attendees']),
    ], [], [], []);

    expect($roles['steward']['capabilities'])->toBe(['read' => true, 'export_attendees' => true, 'scan_tickets' => true]);
});

it('replaces a stored role with the same slug', function (): void {
    $stored = [...storedRoles(), 'event_manager' => ['name' => 'Old', 'capabilities' => ['delete_users' => true]]];

    $roles = $this->compiler->compile($stored, [role('event_manager', null, ['read'])], [], [], []);

    expect($roles['event_manager']['capabilities'])->toBe(['read' => true]);
});

it('drops a marked role that is no longer declared', function (): void {
    $stored = [...storedRoles(), 'gone' => ['name' => 'Gone', 'capabilities' => ['read' => true], '_pollora' => ['managed' => true]]];

    expect($this->compiler->compile($stored, [], [], [], []))->not->toHaveKey('gone');
});

it('modifies an existing role and records what it changed', function (): void {
    $roles = $this->compiler->compile(storedRoles(), [], [modification('editor', ['scan_tickets', 'edit_posts'], ['edit_theme_options', 'not_there'])], [], []);

    expect($roles['editor'])->toBe([
        'name' => 'Editor',
        'capabilities' => ['edit_posts' => true, 'moderate_comments' => true, 'scan_tickets' => true],
        '_pollora' => ['granted' => ['scan_tickets'], 'removed' => ['edit_theme_options' => true]],
    ]);
});

it('undoes previous modifications before applying the current ones', function (): void {
    $previous = $this->compiler->compile(storedRoles(), [], [modification('editor', ['old_grant'], ['moderate_comments'])], [], []);

    $roles = $this->compiler->compile($previous, [], [modification('editor', ['scan_tickets'])], [], []);

    expect($roles['editor']['capabilities'])->toBe(['edit_posts' => true, 'edit_theme_options' => true, 'moderate_comments' => true, 'scan_tickets' => true]);
});

it('gives back the stored roles once nothing is declared any more', function (): void {
    $compiled = $this->compiler->compile(
        storedRoles(),
        [role('event_manager', 'author', ['export_attendees'])],
        [modification('editor', ['scan_tickets'], ['edit_theme_options'])],
        ['administrator'],
        ['edit_events'],
    );

    // Same roles and capabilities; only the order of the restored keys may differ.
    expect($this->compiler->compile($compiled, [], [], ['administrator'], []))->toEqual(storedRoles());
});

it('gives the same result when compiled twice', function (): void {
    $arguments = [
        [role('event_manager', 'author', ['export_attendees'], ['publish_posts'])],
        [modification('editor', ['scan_tickets'], ['edit_theme_options']), modification('event_manager', ['moderate_comments'])],
        ['administrator'],
        ['edit_events', 'export_attendees'],
    ];

    $once = $this->compiler->compile(storedRoles(), ...$arguments);

    expect($this->compiler->compile($once, ...$arguments))->toBe($once);
});

it('applies modifications of a declared role after building it', function (): void {
    $roles = $this->compiler->compile(storedRoles(), [role('event_manager', 'subscriber')], [modification('event_manager', ['moderate_comments'], ['read'])], [], []);

    expect($roles['event_manager']['capabilities'])->toBe(['moderate_comments' => true]);
});

it('lets a declared role inherit what a modification added to its parent', function (): void {
    $roles = $this->compiler->compile(storedRoles(), [role('event_manager', 'subscriber')], [modification('subscriber', ['view_events'])], [], []);

    expect($roles['event_manager']['capabilities'])->toBe(['read' => true, 'view_events' => true]);
});

it('gives the super roles every declared capability', function (): void {
    $roles = $this->compiler->compile(storedRoles(), [], [], ['administrator', 'missing_super_role'], ['edit_events', 'edit_posts']);

    expect($roles['administrator']['capabilities'])->toBe(['manage_options' => true, 'edit_posts' => true, 'edit_events' => true])
        ->and($roles['administrator']['_pollora'])->toBe(['granted' => ['edit_events']]);
});

it('warns about what it cannot apply', function (): void {
    $warnings = [];
    $warn = function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    };

    $roles = $this->compiler->compile(storedRoles(), [
        role('orphan', 'missing_parent', ['read']),
        role('loop_a', 'loop_b'),
        role('loop_b', 'loop_a', ['read']),
    ], [modification('missing_role', ['read'])], [], [], $warn);

    expect($roles['orphan']['capabilities'])->toBe(['read' => true])
        ->and($warnings)->toBe([
            'Class\ModifyMissing_role modifies the role "missing_role", which does not exist.',
            'The role "orphan" inherits from "missing_parent", which does not exist; it starts with no capability.',
            'The role "loop_b" inherits from itself through "loop_a"; inheritance ignored.',
        ]);
});

it('warns nowhere when nobody listens', function (): void {
    expect($this->compiler->compile(storedRoles(), [role('orphan', 'missing_parent')], [], [], []))->toHaveKey('orphan');
});
