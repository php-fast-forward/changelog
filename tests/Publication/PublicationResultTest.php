<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Publication;

use Error;
use FastForward\Changelog\Publication\PublicationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicationResult::class)]
final class PublicationResultTest extends TestCase
{
    public function testSummaryKeepsItsMachineSchemaAndOrderedActionSnapshot(): void
    {
        $sha = str_repeat('a', 40);
        $actions = ['create_tag', 'create_release'];
        $result = new PublicationResult(
            'published',
            '1.2.0',
            'v1.2.0',
            $sha,
            'https://github.com/o/r/releases/tag/v1.2.0',
            $actions,
        );
        $actions[] = 'A later operation is not part of this result.';
        $summary = $result->summary();

        self::assertSame([
            'state' => 'published',
            'version' => '1.2.0',
            'tag' => 'v1.2.0',
            'sha' => $sha,
            'url' => 'https://github.com/o/r/releases/tag/v1.2.0',
            'actions' => ['create_tag', 'create_release'],
        ], $summary);
        self::assertSame(
            '{"state":"published","version":"1.2.0","tag":"v1.2.0","sha":"' . $sha
                . '","url":"https://github.com/o/r/releases/tag/v1.2.0","actions":["create_tag","create_release"]}',
            json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $summary['actions'][] = 'Changing a summary cannot mutate the approved result.';
        self::assertSame(['create_tag', 'create_release'], $result->summary()['actions']);
    }

    public function testMaintenanceSummaryRetainsNullFieldsAndCannotAcquirePublicationActions(): void
    {
        $sha = str_repeat('b', 40);
        $result = new PublicationResult('maintenance', null, null, $sha, null, []);
        self::assertSame([
            'state' => 'maintenance', 'version' => null, 'tag' => null, 'sha' => $sha, 'url' => null, 'actions' => [],
        ], $result->summary());
        $this->expectException(Error::class);

        $result->actions[] = 'create_release';
    }
}
