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

namespace FastForward\Changelog\Console\Input;

use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;

/** Keeps read-only modes distinct from applying a prepared transaction. */
final class MutationInput
{
    #[MapInput]
    public ReleaseInput $release;

    #[Option(description: 'Describe the transaction without writing files or consuming fragments.', shortcut: 'd')]
    public bool $dryRun = false;

    #[Option(description: 'Return exit 1 when a transaction would change managed files.', shortcut: 'c')]
    public bool $check = false;
}
