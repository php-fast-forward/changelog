<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Changeset\Parser;

use FastForward\Changelog\Changeset\ChangesetParseResult;

/**
 * Parses the strict frontmatter subset used by changeset fragments.
 */
interface ChangesetParserInterface
{
    /**
     * Canonical fragment filename pattern, including the Markdown extension.
     *
     * Callers that compose a path from user input MUST validate the complete
     * input against this pattern before joining it to a directory.
     */
    public const string FILENAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.md$/D';

    /**
     * Canonical GitHub login pattern, including the bot-login suffix.
     *
     * Serialized authors omit the leading @. Legacy input MAY include it, and
     * parsing normalizes it before validation.
     */
    public const string AUTHOR_PATTERN = '/^(?!.*--)[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?(?:\[bot\])?$/D';

    /**
     * Parses one fragment and returns all diagnostics without throwing for
     * schema errors.
     *
     * @param string $path     fragment path whose filename becomes its identifier
     * @param string $contents complete Markdown fragment
     */
    public function parse(string $path, string $contents): ChangesetParseResult;
}
