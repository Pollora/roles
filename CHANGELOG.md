# Changelog

All notable changes to `pollora/roles` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-16

### Added

- Roles and capabilities can be declared with a fluent API besides the attributes: `Role::make('slug', 'Label')`, `Role::modify('slug')` and `Role::capabilitySet()`, with `inherits()`, `grants()`, `without()`, `grantsPostType()`, `grantsTaxonomy()`, `allowSensitive()` and `textDomain()`. They carry the names of the attributes and of their arguments
- `RoleDeclarer`, the calls behind `Role` that a framework can bind to its own facade, and `WordPressLateRefresh`, which hands a role declared late to WordPress
- `Role::manager()`, `Role::useLogger()` and `Role::reset()` to wire it to WordPress without Laravel; the declarations are checked and handed to `WP_Roles` lazily, and a role declared later reaches WordPress on `init`
- `RoleDeclaration`, what attributes and the fluent API both fill, and `RoleDefinitionBuilder::assemble()`, which checks it: one code for both, so a mistake is refused with the same reason. A parity test keeps the two in step
- `RoleRegistry::declare()` and `RoleRegistry::refusals()`, with an optional `RoleDefinitionBuilder` as third argument of the constructor (existing wiring is unchanged)

### Changed

- `declaringClass` of a definition declared with the fluent API is the call (`Role::make('slug') at file:line`), not a class; its type is `string`

## [0.1.0] - 2026-10-15

### Added

- The roles classes of the Pollora framework as a package of their own, class names unchanged: `RoleDefinitionBuilder`, `RoleRegistry`, `RoleCompiler`, `PostTypeCapabilityMap`, the capability readers, the WordPress adapters (the stored roles, `WP_Roles` injection, label translation, the users who carry a role) and `RoleDiscovery`
- The ports that bind them to WordPress: `RoleStoreInterface` and `RoleUsageInterface`
- It runs without Laravel: its classes reach for nothing in Illuminate, which an architecture test keeps. The Laravel authorization bridge, the `role` middleware, the `@role` directive, the `pollora:roles:*` commands and the `pollora:doctor` check stay in the framework
