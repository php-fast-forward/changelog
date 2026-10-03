<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Factory;

use FastForward\Changelog\Release\Factory\ReleaseExceptionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ReleaseExceptionFactory::class)]
final class ReleaseExceptionFactoryTest extends TestCase
{
    /** Construction MUST preserve messages and optional causes for both failure categories. */
    public function testDiagnosticsRetainMessagesAndCauses(): void
    {
        $factory = new ReleaseExceptionFactory();
        $cause = new RuntimeException('cause');
        $invalid = $factory->invalid('bad receipt', $cause);
        self::assertInstanceOf(InvalidArgumentException::class, $invalid);
        self::assertSame('bad receipt', $invalid->getMessage());
        self::assertSame($cause, $invalid->getPrevious());
        self::assertNull($factory->invalid('plain')->getPrevious());
        $failure = $factory->failure('disk failed', $cause);
        self::assertSame('disk failed', $failure->getMessage());
        self::assertSame($cause, $failure->getPrevious());
        self::assertNull($factory->failure('plain')->getPrevious());
    }
}
