<?php

declare(strict_types=1);

namespace FastForward\Changelog\Changeset\Store {
    use FastForward\Changelog\Tests\Filesystem\Fixture\DirectoryName;

    /** Supplies native lexical behavior unless a scoped unit test selects this exact path. */
    function dirname(string $path, int $levels = 1): string
    {
        return DirectoryName::resolve($path, $levels);
    }
}

namespace FastForward\Changelog\Filesystem {
    use FastForward\Changelog\Tests\Filesystem\Fixture\DirectoryName;

    /** Supplies native lexical behavior unless a scoped unit test selects this exact path. */
    function dirname(string $path, int $levels = 1): string
    {
        return DirectoryName::resolve($path, $levels);
    }
}
