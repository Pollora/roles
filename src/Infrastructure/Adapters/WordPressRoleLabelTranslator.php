<?php

declare(strict_types=1);

namespace Pollora\Role\Infrastructure\Adapters;

use Pollora\Role\Application\Services\RoleRegistry;

/**
 * Translates the labels of declared roles with their own text domain.
 *
 * WordPress shows role names through `translate_user_role()`, which only looks
 * in the `default` domain with the `User role` context. Hooked on
 * `gettext_with_context_default`, this looks again in the domain the role
 * declares, when WordPress has no translation for the label. It runs when the
 * admin displays a role, long after roles are injected, so translations are
 * never loaded too early.
 */
final readonly class WordPressRoleLabelTranslator
{
    public function __construct(private RoleRegistry $registry) {}

    public function translate(string $translation, string $text, string $context): string
    {
        if ($context !== 'User role' || $translation !== $text) {
            return $translation;
        }

        $domain = $this->registry->labelDomains()[$text] ?? null;

        return $domain === null ? $translation : translate($text, $domain);
    }
}
