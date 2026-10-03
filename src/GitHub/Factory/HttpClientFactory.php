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

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Constructs the Symfony transport without sending traffic during composition. */
final class HttpClientFactory implements HttpClientFactoryInterface
{
    /** Returns a fresh transport; request headers and credentials belong to the adapter. */
    public function create(): HttpClientInterface
    {
        return HttpClient::create();
    }
}
