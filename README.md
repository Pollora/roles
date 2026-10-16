<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/roles.png" width="100%" alt="Pollora Roles: WordPress roles and capabilities declared with attributes">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/roles"><img src="https://img.shields.io/packagist/v/pollora/roles" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/roles"><img src="https://img.shields.io/packagist/dt/pollora/roles" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/roles/actions/workflows/tests.yml"><img src="https://github.com/Pollora/roles/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/roles" alt="License"></a>
</p>

WordPress roles and capabilities declared in code: a class with `#[Role]` is a role, `#[ModifyRole]` adjusts a role WordPress already has, and the capabilities of your post types and taxonomies are granted by naming them. The package compiles the declarations into the roles WordPress stores and puts them in `WP_Roles` when it loads, so the code is the source of truth and the database follows.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it is installed and wired for you: see [Roles & capabilities](https://pollora.dev/advanced/roles-capabilities/). It runs without Laravel too: in a plugin, a Bedrock site or any PHP project. The Laravel authorization bridge (`$user->can()`, `@can`, the `role` middleware and the `@role` directive) stays in the framework.

## Installation

```bash
composer require pollora/roles
```

Requires PHP 8.3+ and WordPress. It builds on [`pollora/attributes`](https://github.com/Pollora/attributes) (the `#[Role]` family) and [`pollora/discovery`](https://github.com/Pollora/discovery).

## Declaring

```php
use Pollora\Attributes\Role;
use Pollora\Attributes\Role\Grants;
use Pollora\Attributes\Role\GrantsPostType;
use Pollora\Attributes\Role\GrantsTaxonomy;
use Pollora\Attributes\Role\Without;
use Pollora\Role\Domain\Enums\Access;

#[Role('event_manager', label: 'Event manager', inherits: 'author')]
#[GrantsPostType(Event::class, Access::Editor)]
#[GrantsTaxonomy(Genre::class)]
#[Grants('export_attendees', 'upload_files')]
#[Without('publish_posts')]
final class EventManager {}
```

The role starts from the capabilities of the role it `inherits`, adds what it grants (a post type's or a taxonomy's own capabilities by class or slug, or any capability by name or from a backed enum marked `#[CapabilitySet]`), and drops what it is `Without`. Without a `label`, it is the class name as words (`Event Manager`). `#[ModifyRole('editor')]` takes the same attributes to adjust a role that is not yours. The super roles (`['administrator']` above, `roles.super_roles` in a Pollora application) receive every capability the code declares.

### Declaring with the fluent API

The same roles can be declared by calling `Role` instead of writing a class. It takes the names of the attributes and of their arguments, and it is checked by the same code: a mistake is refused with the same reason.

```php
use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Role;

Role::make('event_manager', 'Event manager')      // #[Role('event_manager', label: 'Event manager')]
    ->inherits('author')                           // a slug, or the class of a #[Role]
    ->grantsPostType(Event::class, Access::Editor) // #[GrantsPostType]: a class or a slug
    ->grantsTaxonomy(Genre::class)                 // #[GrantsTaxonomy]
    ->grants('export_attendees', EventCap::ScanTickets) // #[Grants]: names or backed enums
    ->without('publish_posts')                     // #[Without]
    ->allowSensitive()                             // allowSensitive: true
    ->textDomain('my-theme');                      // textDomain: 'my-theme'

Role::modify('editor')                             // #[ModifyRole('editor')]
    ->grants('scan_tickets')
    ->without('edit_theme_options');

Role::capabilitySet(EventCap::class);              // #[CapabilitySet]: a backed enum, or a list of names
```

There is nothing to register and nothing to end the chain with: a declaration is checked when WordPress builds its roles, so a chain can be written in any order. A role declared after that (in a theme's `functions.php`) reaches WordPress on `init`; one declared after `wp_loaded` waits for the next load. A declaration that is refused is left out, and the reason, naming the call (`Role::make('meddler') at /path/to/file.php:12`), is logged as a warning.

`Role::manager()` gives the registry the declarations go into, `Role::useLogger()` sets where warnings go (before the first call), and `Role::reset()` forgets everything, for tests. In a Pollora application the `Role` facade does the same and the framework wires it for you.

## Wiring it outside Pollora

Point the discovery engine at your code and add `RoleDiscovery`, then hand the roles to WordPress when it builds `WP_Roles`:

```php
use Pollora\Discovery\Domain\Models\DiscoveryLocation;
use Pollora\Discovery\Infrastructure\Services\DiscoveryEngine;
use Pollora\Role\Application\Services\CapabilityOwnerReader;
use Pollora\Role\Application\Services\RoleDefinitionBuilder;
use Pollora\Role\Application\Services\RoleRegistry;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;
use Pollora\Role\Domain\Services\RoleCompiler;
use Pollora\Role\Infrastructure\Adapters\WordPressRoleInjector;
use Pollora\Role\Infrastructure\Services\RoleDiscovery;

$reader = new CapabilityOwnerReader;
$registry = new RoleRegistry($reader, new PostTypeCapabilityMap);
$injector = new WordPressRoleInjector($registry, new RoleCompiler, ['administrator']);

// Before WordPress builds WP_Roles: the first thing a plugin loads, or an mu-plugin
add_action('wp_roles_init', $injector->inject(...), 1);
add_action('init', $injector->reportWarnings(...), PHP_INT_MAX);

(new DiscoveryEngine)
    ->addLocation(new DiscoveryLocation('MyPlugin\\', __DIR__.'/src'))
    ->addDiscovery('roles', new RoleDiscovery(new RoleDefinitionBuilder($reader, ['administrator']), $registry, $injector))
    ->run();
```

Declarations are read before WordPress loads its roles, so run the engine early. The stored roles themselves are read and written through `WordPressRoleStore` (`RoleStoreInterface`) and the users who carry a role counted through `WordPressRoleUsage` (`RoleUsageInterface`).

## Testing

```bash
composer test   # unit tests, PHPStan, Pint
```

## License

MIT. See [LICENSE](LICENSE).
