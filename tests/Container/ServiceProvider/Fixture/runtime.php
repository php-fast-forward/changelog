<?php

declare(strict_types=1);

namespace FastForward\Changelog\Container\ServiceProvider;

use FastForward\Changelog\Tests\Container\ServiceProvider\Fixture\RuntimeDefaults;

/** Replaces the native process working directory with explicit unit-test state. */
function getcwd(): string|false
{
    ++RuntimeDefaults::$calls;

    return RuntimeDefaults::$workingDirectory;
}

/** Replaces the host's temporary directory with explicit unit-test state. */
function sys_get_temp_dir(): string
{
    ++RuntimeDefaults::$calls;

    return RuntimeDefaults::$temporaryDirectory;
}

/** Replaces filesystem canonicalization without inspecting a real path. */
function realpath(string $path): string|false
{
    ++RuntimeDefaults::$calls;

    if ($path !== RuntimeDefaults::$temporaryDirectory) {
        throw new \LogicException('Unexpected provider filesystem observation.');
    }

    return RuntimeDefaults::$resolvedTemporaryDirectory;
}
