<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset\Store;

use FastForward\Changelog\Changeset\Store\WriteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriteResult::class)]
final class WriteResultTest extends TestCase
{
    #[Test]
    public function casesExposeEveryStableWriteOutcome(): void
    {
        self::assertSame(
            ['available', 'created', 'existing', 'unsafe-path', 'lock-unavailable'],
            array_map(static fn(WriteResult $result): string => $result->value, WriteResult::cases()),
        );
    }
}
