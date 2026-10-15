# Changelog

All notable changes to `pollora/roles` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-15

### Added

- The roles classes of the Pollora framework as a package of their own, class names unchanged: `RoleDefinitionBuilder`, `RoleRegistry`, `RoleCompiler`, `PostTypeCapabilityMap`, the capability readers, the WordPress adapters (the stored roles, `WP_Roles` injection, label translation, the users who carry a role) and `RoleDiscovery`
- The ports that bind them to WordPress: `RoleStoreInterface` and `RoleUsageInterface`
- It runs without Laravel: its classes reach for nothing in Illuminate, which an architecture test keeps. The Laravel authorization bridge, the `role` middleware, the `@role` directive, the `pollora:roles:*` commands and the `pollora:doctor` check stay in the framework
