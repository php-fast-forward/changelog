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

namespace FastForward\Changelog\GitHub\Factory;

use FastForward\Changelog\GitHub\GitHubClientInterface;

/** Composes a GitHub adapter from explicit credentials and a trusted API base. */
interface GitHubClientFactoryInterface
{
    /** Creates the adapter without discovering credentials from host environment or files. */
    public function create(string $token = '', string $apiUrl = 'https://api.github.com'): GitHubClientInterface;
}
