<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History;

use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
final class HistoryDocumentTest extends TestCase
{
    #[Test]
    public function storesRawPresentationAndLooksUpCanonicalVersions(): void
    {
        $release = new HistoryRelease('1.0.0');
        $document = new HistoryDocument([$release], 'prefix', 'references');
        self::assertSame([$release], $document->getReleases());
        self::assertSame('prefix', $document->getPrefix());
        self::assertSame('references', $document->getReferences());
        self::assertSame($release, $document->getRelease('v1.0.0'));
        self::assertNull($document->getRelease('2.0.0'));
        $changed = $document->withReleases([]);
        self::assertSame([], $changed->getReleases());
        self::assertSame([$release], $document->getReleases());
        self::assertSame('prefix', $changed->getPrefix());
    }
}
