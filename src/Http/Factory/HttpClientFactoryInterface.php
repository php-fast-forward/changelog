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

namespace FastForward\Changelog\Http\Factory;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Concentrates concrete HTTP transport construction at the composition boundary. */
interface HttpClientFactoryInterface
{
    /** Creates an HTTP client without starting a request or reading a token. */
    public function create(): HttpClientInterface;
}
