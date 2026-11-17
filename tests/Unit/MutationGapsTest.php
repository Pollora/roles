<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Attributes\CapabilitySet;
use Pollora\Attributes\PostType;
use Pollora\Attributes\Role as RoleAttribute;
use Pollora\Attributes\Role\GrantsPostType;
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDeclaration;
use Pollora\Role\Application\Services\RoleDeclarer;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Domain\Exceptions\InvalidRoleDefinitionException;
use Pollora\Role\Domain\Models\RoleChanges;
use Pollora\Role\Domain\Models\RoleDefinition;
use Pollora\Role\Domain\Models\RoleModification;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;
use Pollora\Role\Domain\Services\RoleCompiler;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleInjector;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleStore;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleUsage;
use Pollora\Role\Infrastructure\Services\RoleDiscovery;
use Pollora\Role\Role;
use Pollora\Role\Tests\Fixtures\EditorAdjustments;
use Pollora\Role\Tests\Fixtures\Event;
use Pollora\Role\Tests\Fixtures\Genre;
use Pollora\Role\Tests\Fixtures\RedeclaresCoreRole;
use Pollora\Role\Tests\Fixtures\Support\Facades\Facade;
use Pollora\Role\Tests\Fixtures\Topic;
use Psr\Log\LoggerInterface;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredEnum;
use Spatie\StructureDiscoverer\Data\DiscoveredInterface;

require_once dirname(__DIR__).'/Fixtures/Invalid.php';
require_once dirname(__DIR__).'/Fixtures/wordpress-roles.php';
require_once dirname(__DIR__).'/Fixtures/Support/Facades/Facade.php';

#[CapabilitySet(label: 'Numbers')]
enum MutIntCap: int
{
    case Alpha = 1;
    case Beta = 2;
}

#[PostType('press-release')]
final class MutPressRelease {}

#[RoleAttribute('presser')]
#[GrantsPostType(MutPressRelease::class)]
final class MutPresser {}

#[CapabilitySet(label: 'Not an enum')]
interface MutSetOnAnInterface {}

#[AllowDynamicProperties]
#[RoleAttribute('multi_attribute')]
final class MutMultiAttribute {}

final class MutProbe
{
    public function __serialize(): array
    {
        return [];
    }

    public function __unserialize(array $data): void
    {
        $GLOBALS['mut_probe_unserialized'] = true;
    }
}

function mutRole(string $slug, ?string $inherits = null, array $grants = [], array $removals = []): RoleDefinition
{
    return new RoleDefinition($slug, 'Label', $inherits, new RoleChanges($grants, $removals), 'Class\\'.$slug);
}

function mutModification(string $slug, array $grants = [], array $removals = [], string $class = 'Class\\A'): RoleModification
{
    return new RoleModification($slug, new RoleChanges($grants, $removals), $class);
}

function mutStored(): array
{
    return [
        'administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true]],
        'editor' => ['name' => 'Editor', 'capabilities' => ['edit_posts' => true, 'edit_theme_options' => true]],
        'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true]],
    ];
}

beforeEach(function (): void {
    $this->owners = new CapabilityOwnerReader;
    $this->registry = new RoleRegistry($this->owners, new PostTypeCapabilityMap);
    $this->builder = new RoleDefinitionBuilder($this->owners);
});

afterEach(function (): void {
    Role::reset();
    unset($GLOBALS['wp_roles'], $GLOBALS['mut_probe_unserialized']);
});

// --- Role: the hooks -------------------------------------------------------

it('hooks the injector early, the warnings last and the label translator on its three arguments', function (): void {
    Role::reset();
    $hooks = [];
    Functions\when('add_action')->alias(function (string $hook, $callback, int $priority = 10, int $arguments = 1) use (&$hooks): bool {
        $hooks[] = ['action', $hook, $priority, $arguments];

        return true;
    });
    Functions\when('add_filter')->alias(function (string $hook, $callback, int $priority = 10, int $arguments = 1) use (&$hooks): bool {
        $hooks[] = ['filter', $hook, $priority, $arguments];

        return true;
    });

    Role::manager();

    expect($hooks)->toBe([
        ['action', 'wp_roles_init', 1, 1],
        ['action', 'init', PHP_INT_MAX, 1],
        ['filter', 'gettext_with_context_default', 10, 3],
    ]);
});

// --- RoleDefinitionBuilder -------------------------------------------------

it('reads a #[ModifyRole] class without touching the attributes of a role', function (): void {
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $modification = $this->builder->build(EditorAdjustments::class);
    } finally {
        restore_error_handler();
    }

    expect($modification)->toBeInstanceOf(RoleModification::class)
        ->and($modification->slug)->toBe('editor');
});

it('keeps each capability once, in order, with no gap in the list', function (): void {
    $declaration = (new RoleDeclaration('usher', 'Usher', false, 'test'))
        ->grants('read', 'read', 'upload_files')
        ->without('publish_posts', 'publish_posts', 'edit_posts');

    $changes = $this->builder->assemble($declaration)->changes;

    expect($changes->grants)->toBe(['read', 'upload_files'])
        ->and($changes->removals)->toBe(['publish_posts', 'edit_posts']);
});

it('turns the capabilities of an int-backed enum into names', function (): void {
    $declaration = (new RoleDeclaration('usher', 'Usher', false, 'test'))->grants(MutIntCap::Alpha)->without(MutIntCap::Beta);

    $changes = $this->builder->assemble($declaration)->changes;

    expect($changes->grants)->toBe(['1'])
        ->and($changes->removals)->toBe(['2']);
});

it('suggests a capability type with underscores for a post type slug with hyphens', function (): void {
    expect(fn () => $this->builder->build(MutPresser::class))->toThrow(
        InvalidRoleDefinitionException::class,
        'the post type "press-release" shares the capabilities of posts; give MutPressRelease its own with #[CapabilityType(\'press_release\')] and #[MapMetaCap].'
    );
});

// --- RoleDeclaration -------------------------------------------------------

it('lists capabilities by position, whatever the names of the arguments', function (): void {
    $declaration = (new RoleDeclaration('usher', 'Usher', false, 'test'))
        ->grants(first: 'read')
        ->grants('upload_files')
        ->without(first: 'publish_posts')
        ->without('edit_posts');

    expect($declaration->grantedCapabilities())->toBe(['read', 'upload_files'])
        ->and($declaration->removedCapabilities())->toBe(['publish_posts', 'edit_posts']);
});

// --- RoleDeclarer ----------------------------------------------------------

it('declares without anyone listening', function (): void {
    $registry = new RoleRegistry($this->owners, new PostTypeCapabilityMap, $this->builder);
    $declarer = new RoleDeclarer($registry);

    $declarer->make('usher', 'Usher');
    $declarer->modify('editor')->grants('moderate_comments');
    $declarer->capabilitySet(['scan_tickets']);

    expect($registry->definitions())->toHaveCount(1)
        ->and($registry->modifications())->toHaveCount(1)
        ->and($registry->declaredCapabilities())->toBe(['scan_tickets']);
});

it('turns the cases of an int-backed enum into capability names', function (): void {
    (new RoleDeclarer($this->registry))->capabilitySet(MutIntCap::class);

    expect($this->registry->declaredCapabilities())->toBe(['1', '2']);
});

it('skips the facade that forwards a call when it names where the project called', function (): void {
    $registry = new RoleRegistry($this->owners, new PostTypeCapabilityMap, $this->builder);

    $line = __LINE__ + 1;
    $declaration = Facade::make(new RoleDeclarer($registry), 'usher');

    expect($declaration->source())->toBe("Role::make('usher') at ".__FILE__.':'.$line);
});

// --- RoleRegistry ----------------------------------------------------------

it('refuses a modification that removes what another class grants on the same role', function (): void {
    $this->registry->addModification(mutModification('editor', ['scan_tickets'], [], 'Class\\A'));

    expect(fn () => $this->registry->addModification(mutModification('editor', [], ['scan_tickets'], 'Class\\B')))
        ->toThrow(InvalidRoleDefinitionException::class, 'it contradicts Class\\A on the role "editor": "scan_tickets" is both granted and removed.');
});

it('names a contradicting capability once', function (): void {
    $this->registry->addModification(mutModification('editor', ['scan_tickets'], ['scan_tickets'], 'Class\\A'));

    try {
        $this->registry->addModification(mutModification('editor', ['scan_tickets'], ['scan_tickets'], 'Class\\B'));
    } catch (InvalidRoleDefinitionException $exception) {
        expect($exception->getMessage())->toContain('on the role "editor": "scan_tickets" is both granted and removed.');

        return;
    }

    $this->fail('The contradiction was not refused.');
});

it('lets two classes adjust one role without contradicting each other', function (): void {
    $this->registry->addModification(mutModification('editor', [], ['edit_theme_options'], 'Class\\A'));
    $this->registry->addModification(mutModification('editor', ['moderate_comments'], [], 'Class\\B'));

    expect($this->registry->modifications())->toHaveCount(2);
});

it('only compares modifications of the same role', function (): void {
    $this->registry->addModification(mutModification('author', ['scan_tickets'], [], 'Class\\A'));
    $this->registry->addModification(mutModification('editor', [], ['scan_tickets'], 'Class\\B'));

    expect($this->registry->modifications())->toHaveCount(2);
});

it('compares a modification with every one declared before it', function (): void {
    $this->registry->addModification(mutModification('author', ['read'], [], 'Class\\Other'));
    $this->registry->addModification(mutModification('editor', ['read'], [], 'Class\\B'));
    $this->registry->addModification(mutModification('editor', ['scan_tickets'], [], 'Class\\A'));

    expect(fn () => $this->registry->addModification(mutModification('editor', [], ['scan_tickets'], 'Class\\B')))
        ->toThrow(InvalidRoleDefinitionException::class, '"scan_tickets" is both granted and removed.');
});

it('records no taxonomy that is not one, or that has no capabilities of its own', function (): void {
    $this->registry->addTaxonomy(Event::class);
    $this->registry->addTaxonomy(Topic::class);

    expect($this->registry->isEmpty())->toBeTrue()
        ->and($this->registry->declaredCapabilities())->toBe([]);
});

it('records no post type that is not one', function (): void {
    $this->registry->addPostType(Genre::class);

    expect($this->registry->isEmpty())->toBeTrue();
});

it('lists the label domains of fluent declarations before anything else reads the roles', function (): void {
    $registry = new RoleRegistry($this->owners, new PostTypeCapabilityMap, $this->builder);
    $registry->declare((new RoleDeclaration('usher', 'Usher', false, 'test'))->textDomain('events'));

    expect($registry->labelDomains())->toBe(['Usher' => 'events']);
});

it('resolves the grants after a post type that cannot be found', function (): void {
    $warnings = [];
    $this->registry->addRole(new RoleDefinition('r', 'R', null, new RoleChanges(postTypeGrants: [
        ['postType' => 'unknown', 'access' => Access::Editor],
        ['postType' => Event::class, 'access' => Access::Editor],
    ]), 'Class\\R'));

    $grants = $this->registry->definitions(function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    })[0]->changes->grants;

    expect($grants)->toContain('edit_others_events')
        ->and($warnings)->toHaveCount(1);
});

it('resolves the grants after a taxonomy that cannot be found', function (): void {
    $warnings = [];
    $this->registry->addRole(new RoleDefinition('r', 'R', null, new RoleChanges(taxonomyGrants: ['unknown', Genre::class]), 'Class\\R'));

    $grants = $this->registry->definitions(function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    })[0]->changes->grants;

    expect($grants)->toBe(['manage_genres', 'edit_genres', 'assign_genres'])
        ->and($warnings)->toHaveCount(1);
});

// --- RoleChanges -----------------------------------------------------------

it('keeps each resolved grant once, with no gap in the list', function (): void {
    $changes = (new RoleChanges(removals: ['publish_posts']))->withResolvedGrants(['read', 'edit_posts', 'read', 'upload_files']);

    expect($changes->grants)->toBe(['read', 'edit_posts', 'upload_files'])
        ->and($changes->removals)->toBe(['publish_posts']);
});

// --- RoleCompiler ----------------------------------------------------------

it('detects an inheritance loop through three roles', function (): void {
    $warnings = [];

    (new RoleCompiler)->compile(mutStored(), [
        mutRole('a', 'b'),
        mutRole('b', 'c'),
        mutRole('c', 'a', ['read']),
    ], [], [], [], function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    });

    expect($warnings)->toBe(['The role "c" inherits from itself through "a"; inheritance ignored.']);
});

it('removes a capability that comes after one the role does not have', function (): void {
    $roles = (new RoleCompiler)->compile(mutStored(), [], [mutModification('editor', [], ['not_there', 'edit_theme_options'])], [], []);

    expect($roles['editor']['capabilities'])->toBe(['edit_posts' => true])
        ->and($roles['editor']['_pollora'])->toBe(['removed' => ['edit_theme_options' => true]]);
});

it('grants a capability that comes after one the role already has', function (): void {
    $roles = (new RoleCompiler)->compile(mutStored(), [], [mutModification('editor', ['edit_posts', 'scan_tickets'])], [], []);

    expect($roles['editor']['capabilities'])->toBe(['edit_posts' => true, 'edit_theme_options' => true, 'scan_tickets' => true])
        ->and($roles['editor']['_pollora'])->toBe(['granted' => ['scan_tickets']]);
});

it('does not remember as removed what the code granted itself', function (): void {
    $roles = (new RoleCompiler)->compile(mutStored(), [], [
        mutModification('editor', ['scan_tickets'], [], 'Class\\A'),
        mutModification('editor', [], ['scan_tickets'], 'Class\\B'),
    ], [], []);

    expect($roles['editor']['capabilities'])->toBe(['edit_posts' => true, 'edit_theme_options' => true])
        ->and($roles['editor']['_pollora'])->toBe(['granted' => ['scan_tickets']]);
});

it('does not remember what it grants to a role it manages', function (): void {
    $roles = (new RoleCompiler)->compile(
        mutStored(),
        [mutRole('event_manager', 'subscriber')],
        [mutModification('event_manager', ['moderate_comments'])],
        ['event_manager'],
        ['edit_events'],
    );

    expect($roles['event_manager']['capabilities'])->toBe(['read' => true, 'moderate_comments' => true, 'edit_events' => true])
        ->and($roles['event_manager']['_pollora'])->toBe(['managed' => true]);
});

it('drops every managed role, whatever comes before it', function (): void {
    $stored = [
        'first' => ['name' => 'First', 'capabilities' => [], '_pollora' => ['managed' => true]],
        ...mutStored(),
        'last' => ['name' => 'Last', 'capabilities' => [], '_pollora' => ['managed' => true]],
    ];

    expect((new RoleCompiler)->compile($stored, [], [], [], []))->toEqual(mutStored());
});

// --- WordPressRoleStore ----------------------------------------------------

it('does nothing for a user WordPress does not have', function (): void {
    Functions\when('get_userdata')->justReturn(false);
    $store = new WordPressRoleStore;

    $store->removeFromUser(9, 'event_manager');
    $store->addRoleToUser(9, 'subscriber');

    expect($store->rolesOf(9))->toBe([]);
});

it('lists the roles of a user, as a list', function (): void {
    $user = Mockery::mock('WP_User');
    $user->roles = [3 => 'editor', 7 => 'author'];
    Functions\when('get_userdata')->justReturn($user);

    expect((new WordPressRoleStore)->rolesOf(4))->toBe(['editor', 'author']);
});

// --- WordPressRoleUsage ----------------------------------------------------

/**
 * @param  array<int, string>  $metaValues  Stored capabilities by user ID
 */
function mutUsageWpdb(array $metaValues): void
{
    $GLOBALS['wpdb'] = new class($metaValues)
    {
        public string $usermeta = 'wp_usermeta';

        public function __construct(private readonly array $metaValues) {}

        public function get_blog_prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        public function get_results(string $query): array
        {
            $rows = [];

            foreach ($this->metaValues as $id => $value) {
                $rows[] = (object) ['user_id' => (string) $id, 'meta_value' => $value];
            }

            return $rows;
        }
    };
}

it('counts entries sorted by name', function (): void {
    mutUsageWpdb([1 => serialize(['zeta' => true, 'alpha' => true]), 2 => serialize(['middle' => true, 'alpha' => true])]);
    Functions\when('is_serialized')->justReturn(true);

    expect((new WordPressRoleUsage)->entries())->toBe(['alpha' => 2, 'middle' => 1, 'zeta' => 1]);
});

it('names the users of an entry by their login', function (): void {
    mutUsageWpdb([5 => serialize(['event_manager' => true]), 6 => serialize(['event_manager' => true])]);
    Functions\when('is_serialized')->justReturn(true);
    $user = Mockery::mock('WP_User');
    $user->user_login = 'bob';
    Functions\when('get_userdata')->alias(fn (int $id) => $id === 5 ? $user : false);

    expect((new WordPressRoleUsage)->usersWith('event_manager'))->toBe([5 => 'bob', 6 => '#6']);
});

it('never instantiates a class found in stored capabilities', function (): void {
    mutUsageWpdb([1 => serialize(['event_manager' => true, 'probe' => new MutProbe])]);
    Functions\when('is_serialized')->justReturn(true);

    $entries = (new WordPressRoleUsage)->entries();

    expect($entries)->toHaveKey('event_manager')
        ->and($GLOBALS['mut_probe_unserialized'] ?? false)->toBeFalse();
});

it('reads stored capabilities padded with whitespace', function (): void {
    mutUsageWpdb([1 => "\n  ".serialize(['event_manager' => true])."  \n"]);
    Functions\when('is_serialized')->justReturn(true);

    expect((new WordPressRoleUsage)->entries())->toBe(['event_manager' => 1]);
});

// --- WordPressRoleInjector -------------------------------------------------

function mutInjectorWithDuplicateWarnings(?LoggerInterface $logger): WordPressRoleInjector
{
    $registry = new RoleRegistry(new CapabilityOwnerReader, new PostTypeCapabilityMap);
    $registry->addRole(new RoleDefinition('r', 'R', null, new RoleChanges(postTypeGrants: [
        ['postType' => 'venue', 'access' => Access::Editor],
        ['postType' => 'venue', 'access' => Access::Author],
        ['postType' => 'stage', 'access' => Access::Editor],
    ]), 'Class\\R'));
    $injector = new WordPressRoleInjector($registry, new RoleCompiler, ['administrator'], $logger);

    $wpRoles = new WP_Roles;
    $wpRoles->roles = mutStored();
    $injector->inject($wpRoles);

    return $injector;
}

it('lists each warning once, as a list', function (): void {
    $injector = mutInjectorWithDuplicateWarnings(null);

    expect($injector->warnings())->toBe([
        'Class\R: the post type "venue" was not found with its own capabilities; its grant is ignored.',
        'Class\R: the post type "stage" was not found with its own capabilities; its grant is ignored.',
    ]);
});

it('logs each warning once', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with('Roles: Class\R: the post type "venue" was not found with its own capabilities; its grant is ignored.');
    $logger->shouldReceive('warning')->once()->with('Roles: Class\R: the post type "stage" was not found with its own capabilities; its grant is ignored.');

    mutInjectorWithDuplicateWarnings($logger)->reportWarnings();
});

it('reports warnings with no logger without failing', function (): void {
    $injector = mutInjectorWithDuplicateWarnings(null);

    $injector->reportWarnings();

    expect($injector->warnings())->toHaveCount(2);
});

// --- RoleDiscovery ---------------------------------------------------------

function mutDiscovery(?LoggerInterface $logger, RoleRegistry $registry): RoleDiscovery
{
    return new RoleDiscovery(
        new RoleDefinitionBuilder(new CapabilityOwnerReader),
        $registry,
        new WordPressRoleInjector($registry, new RoleCompiler),
        $logger,
    );
}

it('ignores a structure that is neither a class nor an enum', function (): void {
    $discovery = mutDiscovery(null, $this->registry);

    $discovery->discover(new DiscoveryLocation('Tests\\', __DIR__), DiscoveredInterface::fromReflection(new ReflectionClass(MutSetOnAnInterface::class)));

    expect(iterator_to_array($discovery->getItems()))->toBe([]);
});

it('finds a declaration among other attributes', function (): void {
    $discovery = mutDiscovery(null, $this->registry);

    $discovery->discover(new DiscoveryLocation('Tests\\', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass(MutMultiAttribute::class)));

    expect(iterator_to_array($discovery->getItems()))->toBe([['class' => '\\'.MutMultiAttribute::class, 'kind' => 'role']]);
});

it('turns the cases of an int-backed #[CapabilitySet] enum into capability names', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldNotReceive('error');
    $discovery = mutDiscovery($logger, $this->registry);

    $discovery->discover(new DiscoveryLocation('Tests\\', __DIR__), DiscoveredEnum::fromReflection(new ReflectionEnum(MutIntCap::class)));
    $discovery->apply();

    expect($this->registry->declaredCapabilities())->toBe(['1', '2']);
});

it('logs the exception of a declaration it cannot register', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once()->with(
        Mockery::type('string'),
        Mockery::on(static fn (array $context): bool => ($context['exception'] ?? null) instanceof InvalidRoleDefinitionException)
    );
    $discovery = mutDiscovery($logger, $this->registry);
    $discovery->discover(new DiscoveryLocation('Tests\\', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass(RedeclaresCoreRole::class)));

    $discovery->apply();
});

it('drops a declaration it cannot register when nobody logs', function (): void {
    $discovery = mutDiscovery(null, $this->registry);
    $discovery->discover(new DiscoveryLocation('Tests\\', __DIR__), DiscoveredClass::fromReflection(new ReflectionClass(RedeclaresCoreRole::class)));

    $discovery->apply();

    expect($this->registry->definitions())->toBe([]);
});
