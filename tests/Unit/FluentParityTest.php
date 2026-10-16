<?php

declare(strict_types=1);

use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Domain\Models\RoleModification;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;
use Pollora\Role\Domain\Services\RoleCompiler;
use Pollora\Role\Role;
use Pollora\Role\Tests\Fixtures\Article;
use Pollora\Role\Tests\Fixtures\AuthorsEvents;
use Pollora\Role\Tests\Fixtures\ContributesEvents;
use Pollora\Role\Tests\Fixtures\EditorAdjustments;
use Pollora\Role\Tests\Fixtures\EditsEvents;
use Pollora\Role\Tests\Fixtures\Event;
use Pollora\Role\Tests\Fixtures\EventCap;
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
use Pollora\Role\Tests\Fixtures\Steward;
use Pollora\Role\Tests\Fixtures\Topic;
use Pollora\Role\Tests\Fixtures\Usher;
use Pollora\Role\Tests\Fixtures\Venue;

require_once dirname(__DIR__).'/Fixtures/Invalid.php';
require_once dirname(__DIR__).'/Fixtures/AccessLevels.php';

/*
|--------------------------------------------------------------------------
| Attributes and the fluent API declare the same roles
|--------------------------------------------------------------------------
|
| The same declaration written both ways must end in the same roles, and a
| mistake must be refused with the same reason. Each case pairs a class that
| carries attributes with the chain that says the same thing.
|
*/

beforeEach(function (): void {
    Role::reset();
});

afterEach(function (): void {
    Role::reset();
});

/**
 * What the registry would hand to the compiler, without where it was declared.
 *
 * @return array{roles: list<array<string, mixed>>, modifications: list<array<string, mixed>>, capabilities: list<string>}
 */
function describeRegistry(RoleRegistry $registry): array
{
    $describe = static fn (RoleDefinition|RoleModification $declaration): array => [
        'slug' => $declaration->slug,
        'label' => $declaration instanceof RoleDefinition ? $declaration->label : null,
        'inherits' => $declaration instanceof RoleDefinition ? $declaration->inherits : null,
        'textDomain' => $declaration instanceof RoleDefinition ? $declaration->textDomain : null,
        'grants' => $declaration->changes->grants,
        'removals' => $declaration->changes->removals,
    ];

    return [
        'roles' => array_map($describe, $registry->definitions()),
        'modifications' => array_map($describe, $registry->modifications()),
        'capabilities' => $registry->declaredCapabilities(),
    ];
}

/**
 * A registry filled from attribute classes, the way discovery fills it.
 *
 * @param  list<class-string>  $declarations
 */
function registryFromAttributes(array $declarations, array $postTypes = [Event::class, Venue::class], array $taxonomies = [Genre::class]): RoleRegistry
{
    $owners = new CapabilityOwnerReader;
    $registry = new RoleRegistry($owners, new PostTypeCapabilityMap);
    $builder = new RoleDefinitionBuilder($owners, ['administrator']);

    foreach ($postTypes as $postType) {
        $registry->addPostType($postType);
    }

    foreach ($taxonomies as $taxonomy) {
        $registry->addTaxonomy($taxonomy);
    }

    foreach ($declarations as $class) {
        $declaration = $builder->build($class);
        $declaration instanceof RoleDefinition ? $registry->addRole($declaration) : $registry->addModification($declaration);
    }

    return $registry;
}

/**
 * The registry the fluent API fills, with the same post types and taxonomies known.
 */
function fluentRegistry(array $postTypes = [Event::class, Venue::class], array $taxonomies = [Genre::class]): RoleRegistry
{
    $registry = Role::manager();

    foreach ($postTypes as $postType) {
        $registry->addPostType($postType);
    }

    foreach ($taxonomies as $taxonomy) {
        $registry->addTaxonomy($taxonomy);
    }

    return $registry;
}

it('declares the same roles with the fluent API as with attributes', function (string $class, Closure $declare): void {
    $declare();

    expect(describeRegistry(fluentRegistry()))->toBe(describeRegistry(registryFromAttributes([$class])));
})->with([
    'a role with every kind of grant' => [EventManager::class, fn () => Role::make('event_manager', 'Event manager')
        ->inherits('author')
        ->grantsPostType(Event::class, Access::Editor)
        ->grantsPostType('venue', Access::Author)
        ->grantsTaxonomy(Genre::class)
        ->grants(EventCap::ExportAttendees, 'scan_tickets')
        ->grants('upload_files')
        ->without('publish_posts')],
    'a role that inherits a role given by class, and is allowed a sensitive capability' => [Steward::class, fn () => Role::make('steward', 'Steward')
        ->inherits(EventManager::class)
        ->allowSensitive()
        ->grants('manage_options')],
    'a role with a text domain' => [Usher::class, fn () => Role::make('usher', 'Usher')
        ->inherits('subscriber')
        ->textDomain('events')],
    'a modification of a role WordPress has' => [EditorAdjustments::class, fn () => Role::modify('editor')
        ->grantsPostType(Event::class, Access::Contributor)
        ->grants(EventCap::ScanTickets)
        ->without('edit_theme_options')],
    'post type access: contributor' => [ContributesEvents::class, fn () => Role::make('event_contributor', 'Event contributor')->grantsPostType(Event::class, Access::Contributor)],
    'post type access: author' => [AuthorsEvents::class, fn () => Role::make('event_author', 'Event author')->grantsPostType(Event::class, Access::Author)],
    'post type access: editor, the default' => [EditsEvents::class, fn () => Role::make('event_editor', 'Event editor')->grantsPostType(Event::class)],
]);

it('compiles a fluent declaration into the same roles WordPress stores as its attributes', function (): void {
    $stored = [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true, 'edit_posts' => true]],
        'editor' => ['name' => 'Editor', 'capabilities' => ['edit_posts' => true, 'edit_theme_options' => true]],
        'author' => ['name' => 'Author', 'capabilities' => ['read' => true, 'edit_posts' => true, 'publish_posts' => true, 'upload_files' => true]],
        'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true]],
    ];
    $compile = static fn (RoleRegistry $registry): array => (new RoleCompiler)->compile(
        $stored,
        $registry->definitions(),
        $registry->modifications(),
        ['administrator'],
        $registry->declaredCapabilities(),
    );

    Role::make('event_manager', 'Event manager')
        ->inherits('author')
        ->grantsPostType(Event::class, Access::Editor)
        ->grantsPostType('venue', Access::Author)
        ->grantsTaxonomy(Genre::class)
        ->grants(EventCap::ExportAttendees, 'scan_tickets')
        ->grants('upload_files')
        ->without('publish_posts');
    Role::modify('editor')
        ->grantsPostType(Event::class, Access::Contributor)
        ->grants(EventCap::ScanTickets)
        ->without('edit_theme_options');
    Role::capabilitySet(EventCap::class);

    $fluent = $compile(fluentRegistry());
    $viaAttributes = registryFromAttributes([EventManager::class, EditorAdjustments::class]);
    $viaAttributes->addCapabilitySet(array_map(static fn (EventCap $case): string => $case->value, EventCap::cases()));
    $attributes = $compile($viaAttributes);

    expect($fluent)->toBe($attributes)
        ->and($fluent['event_manager']['capabilities'])->toHaveKeys(['export_attendees', 'edit_others_events'])
        ->and($fluent['editor']['capabilities'])->not->toHaveKey('edit_theme_options');
});

it('declares a capability set from an enum, as #[CapabilitySet] does', function (): void {
    Role::capabilitySet(EventCap::class);

    $viaAttribute = new RoleRegistry(new CapabilityOwnerReader, new PostTypeCapabilityMap);
    $viaAttribute->addCapabilitySet(array_map(static fn (EventCap $case): string => $case->value, EventCap::cases()));

    expect(fluentRegistry([], [])->declaredCapabilities())->toBe($viaAttribute->declaredCapabilities());
});

it('declares a capability set from a list of names', function (): void {
    Role::capabilitySet(['export_attendees', 'scan_tickets']);

    expect(fluentRegistry([], [])->declaredCapabilities())->toBe(['export_attendees', 'scan_tickets']);
});

/**
 * Why the attribute class is refused, without the place it came from.
 */
function attributeRefusal(string $class): string
{
    try {
        (new RoleDefinitionBuilder(new CapabilityOwnerReader, ['administrator']))->build($class);
    } catch (Throwable $throwable) {
        return explode(': ', $throwable->getMessage(), 2)[1];
    }

    throw new LogicException($class.' was not refused.');
}

it('refuses a mistake with the same reason in the fluent API as with attributes', function (string $class, Closure $declare): void {
    $reason = attributeRefusal($class);

    $declare();
    $refusals = fluentRegistry([Event::class, Article::class], [Genre::class, Topic::class])->refusals();

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0])->toContain($reason)
        ->and($refusals[0])->toContain('Role::')
        ->and($refusals[0])->toContain('FluentParityTest.php:');
})->with([
    'a core role redeclared' => [RedeclaresCoreRole::class, fn () => Role::make('editor', 'Editor')],
    'a super role inherited' => [InheritsSuperRole::class, fn () => Role::make('boss', 'Boss')->inherits('administrator')],
    'a sensitive capability' => [GrantsSensitive::class, fn () => Role::make('meddler', 'Meddler')->grants('edit_users')],
    'a sensitive capability in a modification' => [ModifiesWithSensitive::class, fn () => Role::modify('author')->grants('install_plugins')],
    'a capability granted and removed' => [GrantsAndRemoves::class, fn () => Role::make('undecided', 'Undecided')->grants('read')->without('read')],
    'a post type sharing the capabilities of posts' => [GrantsSharedPostType::class, fn () => Role::make('lost', 'Lost')->grantsPostType(Article::class)],
    'a class that is not a post type' => [GrantsNotAPostType::class, fn () => Role::make('wrong_post_type', 'Wrong')->grantsPostType(EventCap::class)],
    'a taxonomy sharing the capabilities of categories' => [GrantsSharedTaxonomy::class, fn () => Role::make('shared_tax', 'Shared')->grantsTaxonomy(Topic::class)],
    'a class that is not a taxonomy' => [GrantsNotATaxonomy::class, fn () => Role::make('wrong_tax', 'Wrong')->grantsTaxonomy(Event::class)],
    'an inherited class that is not a role' => [InheritsNotARole::class, fn () => Role::make('orphan', 'Orphan')->inherits(NoRoleAttribute::class)],
]);
