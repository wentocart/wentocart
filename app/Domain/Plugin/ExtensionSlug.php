<?php

namespace App\Domain\Plugin;

/**
 * Technical folder / id slugs for extensions (Option B).
 * Human brands stay in app.json display_name / author.
 */
final class ExtensionSlug
{
    /** Letters and hyphens only; must start with a letter. */
    public const PATTERN = '/^[a-z][a-z-]{1,32}$/';

    public const ID_PATTERN = '/^[a-z][a-z-]{1,32}\/[a-z][a-z-]{1,32}$/';

    public static function isValid(string $slug): bool
    {
        return (bool) preg_match(self::PATTERN, $slug);
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match(self::ID_PATTERN, $id);
    }
}
