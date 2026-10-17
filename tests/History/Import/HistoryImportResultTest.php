<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History\Import;

use Error;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\HistoryImportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryImportResult::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
final class HistoryImportResultTest extends TestCase
{
    public function testImportRetainsItsExactDocumentAndTagBaselineWithoutReorderingInsertedVersions(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('1.0.1', body: 'Maintained but not yet tagged.'),
            new HistoryRelease('1.0.0', body: "Published notes.  \r\n"),
        ], "# Exact introduction\r\n", "[1.0.0]: https://custom.test/first\r\n");
        $missing = ['0.9.0', '0.8.0'];
        $result = new HistoryImportResult($document, $missing, '1.0.0');
        $missing[] = '0.7.0';

        self::assertSame($document, $result->document);
        self::assertSame('1.0.0', $result->currentVersion);
        self::assertSame(['0.9.0', '0.8.0'], $result->missingVersions);
        self::assertSame("Published notes.  \r\n", $result->document->getRelease('1.0.0')->getBody());
        self::assertSame("[1.0.0]: https://custom.test/first\r\n", $result->document->getReferences());
    }

    public function testEmptyImportRetainsTheInitialBaselineAndCannotAcquireInsertedVersions(): void
    {
        $result = new HistoryImportResult(new HistoryDocument(), [], '0.0.0');
        self::assertSame([], $result->document->getReleases());
        self::assertSame([], $result->missingVersions);
        self::assertSame('0.0.0', $result->currentVersion);
        $this->expectException(Error::class);

        $result->missingVersions[] = '1.0.0';
    }
}
