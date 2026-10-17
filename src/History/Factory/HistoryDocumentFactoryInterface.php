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

/** Constructs the sole retained historical document. */
interface HistoryDocumentFactoryInterface
{
    /**
     * Creates a document while validating unique canonical release identities.
     *
     * @param list<\FastForward\Changelog\History\HistoryRelease> $releases   historical sections
     * @param string                                              $prefix     Raw introductory Markdown preceding the first release boundary.
     * @param string                                              $references Raw terminal Markdown reference definitions and preserved following text.
     *
     * @return HistoryDocument History value with unique canonical section identities and unchanged introduction and footer.
     *
     * @throws \InvalidArgumentException When a section is not a HistoryRelease or repeats a canonical version identity.
     */
    public function create(array $releases = [], string $prefix = '', string $references = ''): HistoryDocument;
}
