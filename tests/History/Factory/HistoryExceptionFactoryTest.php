<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History\Factory;

use FastForward\Changelog\History\Factory\HistoryExceptionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryExceptionFactory::class)]
final class HistoryExceptionFactoryTest extends TestCase
{
    #[Test]
    public function constructsTheRequestedDiagnostic(): void
    {
        self::assertSame('diagnostic', new HistoryExceptionFactory()->invalid('diagnostic')->getMessage());
    }
}
