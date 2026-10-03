<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\History\Factory;

use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use InvalidArgumentException;

/** Constructs documents after rejecting duplicate or unsupported section values. */
final class HistoryDocumentFactory implements HistoryDocumentFactoryInterface
{
    /** Validates section identities before storing raw introduction and footer bytes. */
    public function create(array $releases = [], string $prefix = '', string $references = ''): HistoryDocument
    {
        $versions = [];

        foreach ($releases as $release) {
            if (! $release instanceof HistoryRelease || in_array($release->getVersion(), $versions, true)) {
                throw new InvalidArgumentException('History releases must have unique canonical version identities.');
            }

            $versions[] = $release->getVersion();
        }

        return new HistoryDocument(array_values($releases), $prefix, $references);
    }
}
