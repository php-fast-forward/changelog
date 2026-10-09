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

use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Http\Factory\HttpClientFactoryInterface;

/** Centralizes concrete adapter construction while retaining mocked transport boundaries. */
final readonly class GitHubClientFactory implements GitHubClientFactoryInterface
{
    /** Injects transport and diagnostic construction without reading host credentials. */
    public function __construct(
        private HttpClientFactoryInterface $clients,
        private GitHubExceptionFactoryInterface $exceptions,
    ) {}

    /** Builds an adapter from caller-selected credentials and API origin. */
    public function create(string $token = '', string $apiUrl = 'https://api.github.com'): GitHubClientInterface
    {
        return new GitHubClient($this->clients->create(), $this->exceptions, $token, $apiUrl);
    }
}
