<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Filesystem\Fixture;

use Closure;

/** Controls one lexical parent spelling without inspecting the host filesystem. */
final class DirectoryName
{
    /** @var array<string, string> */
    private static array $parents = [];

    /** Delegates every unselected path and depth to the native lexical function. */
    public static function resolve(string $path, int $levels = 1): string
    {
        if (1 === $levels && array_key_exists($path, self::$parents)) {
            return self::$parents[$path];
        }

        return \dirname($path, $levels);
    }

    /** Restores all overrides even when the operation raises an exception or assertion failure. */
    public static function withParent(string $path, string $parent, Closure $operation): void
    {
        $previous = self::$parents;
        self::$parents[$path] = $parent;

        try {
            $operation();
        } finally {
            self::$parents = $previous;
        }
    }
}
