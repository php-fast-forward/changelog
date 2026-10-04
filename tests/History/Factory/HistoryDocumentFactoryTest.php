<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History\Factory;

use FastForward\Changelog\History\Factory\HistoryDocumentFactory;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryDocumentFactory::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
final class HistoryDocumentFactoryTest extends TestCase
{
    #[Test]
    public function createsAUniqueHistoryWithRawPresentation(): void
    {
        $release = new HistoryRelease('1.0.0');
        $document = new HistoryDocumentFactory()->create([$release], 'prefix', 'footer');
        self::assertSame([$release], $document->getReleases());
        self::assertSame('prefix', $document->getPrefix());
        self::assertSame('footer', $document->getReferences());
    }

    #[Test]
    public function rejectsDuplicateCanonicalVersions(): void
    {
        $release = new HistoryRelease('1.0.0');
        $this->expectException(InvalidArgumentException::class);
        new HistoryDocumentFactory()->create([$release, $release]);
    }

    #[Test]
    public function rejectsNonReleaseValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HistoryDocumentFactory()->create(['unsupported']);
    }
}
