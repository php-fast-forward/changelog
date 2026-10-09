<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Validator;

use InvalidArgumentException;

/** Validates an interactive description without normalizing significant Markdown bytes. */
final readonly class ChangeDescriptionValidator
{
    /** Returns the original answer; rejects absent or whitespace-only text without any side effects. */
    public function __invoke(?string $value): string
    {
        if (null === $value || '' === trim($value)) {
            throw new InvalidArgumentException('The change description must contain meaningful text.', 2);
        }

        return $value;
    }
}
