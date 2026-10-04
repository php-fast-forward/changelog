<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History\Import\Factory;

use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\Import\Factory\HistoryImportResultFactory;
use FastForward\Changelog\History\Import\HistoryImportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryImportResultFactory::class)]
#[CoversClass(HistoryImportResult::class)]
#[UsesClass(HistoryDocument::class)]
final class HistoryImportResultFactoryTest extends TestCase
{
    #[Test]
    public function retainsExactEvidenceAndCanonicalBaseline(): void
    {
        $document = new HistoryDocument();
        $result = new HistoryImportResultFactory()->create($document, ['2.0.0', '1.0.0'], '2.0.0');
        self::assertSame($document, $result->document);
        self::assertSame(['2.0.0', '1.0.0'], $result->missingVersions);
        self::assertSame('2.0.0', $result->currentVersion);
    }
}
