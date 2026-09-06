<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Document;

use FastForward\Changelog\Document\ChangelogReleaseFactory;
use FastForward\Changelog\Entry\ChangelogEntryType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogReleaseFactory::class)]
#[UsesClass(\FastForward\Changelog\Document\ChangelogRelease::class)]
final class ChangelogReleaseFactoryTest extends TestCase
{
    #[Test]
    public function createBuildsAReleaseFromEveryArgument(): void
    {
        $release = (new ChangelogReleaseFactory())->create('1.2.3', '2026-09-05', ['Added' => ['entry']]);

        self::assertSame('1.2.3', $release->getVersion());
        self::assertSame('2026-09-05', $release->getDate());
        self::assertSame(['entry'], $release->getEntriesFor(ChangelogEntryType::Added));
    }
}
