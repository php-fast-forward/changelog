<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Container\ServiceProvider\Fixture;

/** Injects synthetic host observations into the provider's lexical runtime boundary. */
final class RuntimeDefaults
{
    public static string|false $workingDirectory = '/consumer';
    public static string $temporaryDirectory = '/temporary-link';
    public static string|false $resolvedTemporaryDirectory = '/synthetic-temp';
    public static int $calls = 0;
}
