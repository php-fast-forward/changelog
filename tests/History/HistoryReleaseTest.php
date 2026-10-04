<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History;

use FastForward\Changelog\History\HistoryRelease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryRelease::class)]
final class HistoryReleaseTest extends TestCase
{
    #[Test]
    public function retainsEveryLosslessField(): void
    {
        $release = new HistoryRelease('1.0.0', '2026-10-03', 'published_at', "\nraw body\n", "## [1.0.0]\n", "end\n");
        self::assertSame('1.0.0', $release->getVersion());
        self::assertSame('2026-10-03', $release->getDate());
        self::assertSame('published_at', $release->getDateSource());
        self::assertSame("\nraw body\n", $release->getBody());
        self::assertSame("## [1.0.0]\n", $release->getHeading());
        self::assertSame("end\n", $release->getEnding());
    }
}
