<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Filesystem\Factory;

use FastForward\Changelog\Filesystem\Factory\PathExceptionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathExceptionFactory::class)]
final class PathExceptionFactoryTest extends TestCase
{
    #[Test]
    public function identifiesTheRejectedPathWithoutInspectingIt(): void
    {
        $failure = new PathExceptionFactory()->create('../unsafe.md');
        self::assertInstanceOf(InvalidArgumentException::class, $failure);
        self::assertStringContainsString('../unsafe.md', $failure->getMessage());
        self::assertStringContainsString('absolute regular path', $failure->getMessage());
    }
}
