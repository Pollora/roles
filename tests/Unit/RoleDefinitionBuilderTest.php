<?php

declare(strict_types=1);

use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Domain\Exceptions\InvalidRoleDefinitionException;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Domain\Models\RoleModification;
use Pollora\Role\Tests\Fixtures\EditorAdjustments;
use Pollora\Role\Tests\Fixtures\Event;
use Pollora\Role\Tests\Fixtures\EventManager;
use Pollora\Role\Tests\Fixtures\Genre;
use Pollora\Role\Tests\Fixtures\GrantsAndRemoves;
use Pollora\Role\Tests\Fixtures\GrantsNotAPostType;
use Pollora\Role\Tests\Fixtures\GrantsNotATaxonomy;
use Pollora\Role\Tests\Fixtures\GrantsSensitive;
use Pollora\Role\Tests\Fixtures\GrantsSharedPostType;
use Pollora\Role\Tests\Fixtures\GrantsSharedTaxonomy;
use Pollora\Role\Tests\Fixtures\InheritsNotARole;
use Pollora\Role\Tests\Fixtures\InheritsSuperRole;
use Pollora\Role\Tests\Fixtures\ModifiesWithSensitive;
use Pollora\Role\Tests\Fixtures\NoRoleAttribute;
use Pollora\Role\Tests\Fixtures\RedeclaresCoreRole;
use Pollora\Role\Tests\Fixtures\RoleAndModify;
use Pollora\Role\Tests\Fixtures\Steward;
use Pollora\Role\Tests\Fixtures\Usher;

require_once dirname(__DIR__).'/Fixtures/Invalid.php';

beforeEach(function (): void {
    $this->builder = new RoleDefinitionBuilder(new CapabilityOwnerReader, ['administrator']);
});

it('builds a role from #[Role] and its grants', function (): void {
    $role = $this->builder->build(EventManager::class);

    expect($role)->toBeInstanceOf(RoleDefinition::class)
        ->and($role->slug)->toBe('event_manager')
        ->and($role->label)->toBe('Event manager')
        ->and($role->textDomain)->toBeNull()
        ->and($role->inherits)->toBe('author')
        ->and($role->declaringClass)->toBe(EventManager::class)
        ->and($role->changes->grants)->toBe(['export_attendees', 'scan_tickets', 'upload_files'])
        ->and($role->changes->removals)->toBe(['publish_posts'])
        ->and($role->changes->postTypeGrants)->toBe([
            ['postType' => Event::class, 'access' => Access::Editor],
            ['postType' => 'venue', 'access' => Access::Author],
        ])
        ->and($role->changes->taxonomyGrants)->toBe([Genre::class]);
});

it('resolves an inherited role given by class, and defaults the label to the class name', function (): void {
    $role = $this->builder->build(Steward::class);

    expect($role->inherits)->toBe('event_manager')
        ->and($role->label)->toBe('Steward')
        ->and($role->changes->grants)->toBe(['manage_options']);
});

it('keeps the text domain of the label, through resolution', function (): void {
    $role = $this->builder->build(Usher::class);

    expect($role->textDomain)->toBe('events')
        ->and($role->withChanges($role->changes)->textDomain)->toBe('events');
});

it('builds a modification from #[ModifyRole]', function (): void {
    $modification = $this->builder->build(EditorAdjustments::class);

    expect($modification)->toBeInstanceOf(RoleModification::class)
        ->and($modification->slug)->toBe('editor')
        ->and($modification->changes->grants)->toBe(['scan_tickets'])
        ->and($modification->changes->removals)->toBe(['edit_theme_options'])
        ->and($modification->changes->postTypeGrants)->toBe([['postType' => Event::class, 'access' => Access::Contributor]]);
});

it('refuses a declaration that would grant the wrong rights', function (string $class, string $message): void {
    expect(fn () => $this->builder->build($class))->toThrow(InvalidRoleDefinitionException::class, $message);
})->with([
    'core role redeclared' => [RedeclaresCoreRole::class, '"editor" is a core role; adjust it with #[ModifyRole(\'editor\')]'],
    'inherits a super role' => [InheritsSuperRole::class, 'cannot inherit from the super role "administrator"'],
    'sensitive capability' => [GrantsSensitive::class, 'granting "edit_users" lets a user take over the site; confirm it with allowSensitive: true'],
    'sensitive capability on a modification' => [ModifiesWithSensitive::class, 'granting "install_plugins"'],
    'granted and removed' => [GrantsAndRemoves::class, '"read" is both granted and removed'],
    'role and modification' => [RoleAndModify::class, 'not both'],
    'no attribute' => [NoRoleAttribute::class, 'carries neither #[Role] nor #[ModifyRole]'],
    'post type sharing post capabilities' => [GrantsSharedPostType::class, 'the post type "article" shares the capabilities of posts; give Pollora\Role\Tests\Fixtures\Article its own with #[CapabilityType(\'article\')]'],
    'not a post type' => [GrantsNotAPostType::class, 'which is not a #[PostType]'],
    'taxonomy sharing category capabilities' => [GrantsSharedTaxonomy::class, 'the taxonomy "topic" shares the capabilities of categories'],
    'not a taxonomy' => [GrantsNotATaxonomy::class, 'which is not a #[Taxonomy]'],
    'inherits a class that is not a role' => [InheritsNotARole::class, 'which is not a #[Role]'],
]);

it('reads post type and taxonomy capabilities, and ignores other classes', function (): void {
    $owners = new CapabilityOwnerReader;

    expect($owners->postType(Event::class))->toBe(['slug' => 'event', 'capabilityType' => 'event', 'overrides' => []])
        ->and($owners->taxonomy(Genre::class))->toBe(['slug' => 'genre', 'capabilities' => ['manage_genres', 'edit_genres', 'assign_genres']])
        ->and($owners->postType(Genre::class))->toBeNull()
        ->and($owners->taxonomy(Event::class))->toBeNull()
        ->and($owners->postType('not-a-class'))->toBeNull()
        ->and($owners->taxonomy('not-a-class'))->toBeNull();
});
