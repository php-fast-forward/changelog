<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Fragment\Factory;

use FastForward\Changelog\Fragment\Factory\FragmentExceptionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FragmentExceptionFactory::class)]
final class FragmentExceptionFactoryTest extends TestCase
{
    #[Test]
    public function preservesDiagnosticsAndTheOriginalIntegrationCause(): void
    {
        $factory = new FragmentExceptionFactory();
        $invalid = $factory->invalid('invalid fragment');
        self::assertInstanceOf(InvalidArgumentException::class, $invalid);
        self::assertSame('invalid fragment', $invalid->getMessage());
        $original = new RuntimeException('disk error');
        $failure = $factory->failure('cannot create /repo/.changelog/file.md', $original);
        self::assertSame($original, $failure->getPrevious());
        self::assertStringContainsString('/repo/.changelog/file.md', $failure->getMessage());
        self::assertNull($factory->failure('context failure')->getPrevious());
    }
}
