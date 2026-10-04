<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Filesystem\Fixture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class DirectoryNameTest extends TestCase
{
    /** Nested and failed operations must restore earlier overrides and leave unrelated native calls intact. */
    public function testOverridesRemainScopedEvenWhenTheOperationFails(): void
    {
        $path = 'C:/consumer';
        $native = \dirname($path);

        DirectoryName::withParent($path, 'C:/', static function () use ($path): void {
            self::assertSame('C:/', DirectoryName::resolve($path));
            self::assertSame(\dirname('C:/other'), DirectoryName::resolve('C:/other'));
            self::assertSame(\dirname($path, 2), DirectoryName::resolve($path, 2));

            try {
                DirectoryName::withParent($path, 'C:', static function () use ($path): void {
                    self::assertSame('C:', DirectoryName::resolve($path));
                    throw new RuntimeException('operation failed');
                });
                self::fail('The operation failure must propagate.');
            } catch (RuntimeException $error) {
                self::assertSame('operation failed', $error->getMessage());
            }

            self::assertSame('C:/', DirectoryName::resolve($path));
        });

        self::assertSame($native, DirectoryName::resolve($path));
    }
}
