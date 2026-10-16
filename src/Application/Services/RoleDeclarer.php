<?php

declare(strict_types=1);

namespace Pollora\Role\Application\Services;

use BackedEnum;
use Closure;

/**
 * The calls that declare roles and capabilities with the fluent API: what the
 * `Role` class forwards to without Laravel, and what the framework's `Role`
 * facade forwards to.
 *
 * A declaration goes into the registry, which checks it when the roles are
 * first read.
 */
final readonly class RoleDeclarer
{
    /**
     * @param  Closure(): void|null  $declared  Called after each declaration, to hand late ones to WordPress
     */
    public function __construct(
        private RoleRegistry $registry,
        private ?Closure $declared = null,
    ) {}

    /**
     * Declares a role.
     */
    public function make(string $slug, string $label): RoleDeclaration
    {
        return $this->add(new RoleDeclaration($slug, $label, false, sprintf("Role::make('%s') at %s", $slug, $this->caller())));
    }

    /**
     * Adjusts a role WordPress already has: a core role, or one a plugin adds.
     */
    public function modify(string $slug): RoleDeclaration
    {
        return $this->add(new RoleDeclaration($slug, null, true, sprintf("Role::modify('%s') at %s", $slug, $this->caller())));
    }

    /**
     * Declares capabilities that belong to the project, so the super roles receive them.
     *
     * @param  class-string<BackedEnum>|list<string>  $capabilities  A backed enum of capabilities, or their names
     */
    public function capabilitySet(string|array $capabilities): void
    {
        $this->registry->addCapabilitySet(is_array($capabilities)
            ? $capabilities
            : array_map(static fn (BackedEnum $case): string => (string) $case->value, $capabilities::cases()));

        if ($this->declared instanceof Closure) {
            ($this->declared)();
        }
    }

    private function add(RoleDeclaration $declaration): RoleDeclaration
    {
        $this->registry->declare($declaration);

        if ($this->declared instanceof Closure) {
            ($this->declared)();
        }

        return $declaration;
    }

    /**
     * Where the project called, skipping this package and the facade that forwards to it.
     */
    private function caller(): string
    {
        $package = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_starts_with($file, $package) || str_ends_with($file, 'Support/Facades/Facade.php')) {
                continue;
            }

            return sprintf('%s:%d', $file, $frame['line'] ?? 0);
        }

        return 'unknown';
    }
}
