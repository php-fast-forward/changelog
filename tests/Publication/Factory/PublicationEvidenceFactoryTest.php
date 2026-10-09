<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Publication\Factory;

use FastForward\Changelog\Publication\Factory\PublicationEvidenceFactory;
use FastForward\Changelog\Publication\PublicationEvidence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicationEvidenceFactory::class)]
#[CoversClass(PublicationEvidence::class)]
final class PublicationEvidenceFactoryTest extends TestCase
{
    /** Evidence construction MUST perform no lookup or note transformation. */
    public function testCapturesExactApprovedNotes(): void
    {
        $evidence = new PublicationEvidenceFactory()->create(
            str_repeat('b', 40),
            '1.0.1',
            'v1.0.1',
            "Exact  notes\n",
            'owner/repo',
        );
        self::assertSame(str_repeat('b', 40), $evidence->sha);
        self::assertSame('1.0.1', $evidence->version);
        self::assertSame('v1.0.1', $evidence->tag);
        self::assertSame("Exact  notes\n", $evidence->notes);
        self::assertSame('owner/repo', $evidence->repository);
    }
}
