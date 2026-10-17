<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Publication;

use Error;
use FastForward\Changelog\Publication\PublicationEvidence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicationEvidence::class)]
final class PublicationEvidenceTest extends TestCase
{
    public function testApprovedIdentityAndNotesRetainEverySignificantByte(): void
    {
        $sha = str_repeat('a', 40);
        $notes = "### Fixed\r\n\r\n- Preserves two spaces.  \r\n\r\n```text\r\nexact\r\n```\r\n";
        $evidence = new PublicationEvidence($sha, '1.2.0+build.7', 'pkg/1.2.0+build.7', $notes, 'owner/project');

        self::assertSame([
            'sha' => $sha,
            'version' => '1.2.0+build.7',
            'tag' => 'pkg/1.2.0+build.7',
            'notes' => $notes,
            'repository' => 'owner/project',
        ], get_object_vars($evidence));
    }

    public function testMaintenanceEvidenceDoesNotInventReleaseMetadataAndCannotChangeApproval(): void
    {
        $sha = str_repeat('b', 40);
        $evidence = new PublicationEvidence($sha, null, null, '', null);
        self::assertSame([$sha, null, null, '', null], array_values(get_object_vars($evidence)));
        $this->expectException(Error::class);

        $evidence->sha = str_repeat('c', 40);
    }
}
