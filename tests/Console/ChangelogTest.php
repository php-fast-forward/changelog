<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Tests\Console;

use DI\Container;
use FastForward\Changelog\Console\Changelog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Changelog::class)]
final class ChangelogTest extends TestCase
{
    #[Test]
    public function applicationWillRegisterTheStandaloneChangelogCommands(): void
    {
        $application = new Changelog(new Container());

        self::assertTrue($application->has('changelog:entry'));
        self::assertTrue($application->has('changelog:promote'));
        self::assertTrue($application->has('changelog:resolve-version'));
        self::assertTrue($application->has('changelog:render-release-notes'));
        self::assertTrue($application->has('changelog:next-version'));
        self::assertTrue($application->has('changelog:show'));
    }
}
