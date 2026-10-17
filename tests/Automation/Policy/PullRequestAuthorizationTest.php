<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Policy;

use Error;
use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PullRequestAuthorization::class)]
final class PullRequestAuthorizationTest extends TestCase
{
    public function testIndependentGrantsRetainTheirInspectedHeadAndOrderedDiagnostics(): void
    {
        $sha = str_repeat('a', 40);
        $diagnostics = ['Managed version ownership was verified.', 'No fragment waiver was granted.'];
        $authorization = new PullRequestAuthorization(false, true, 'version', $diagnostics, $sha);
        $diagnostics[0] = 'A later observation cannot change this snapshot.';

        self::assertSame([
            'waiverAuthorized' => false,
            'centralChangeAuthorized' => true,
            'kind' => 'version',
            'diagnostics' => ['Managed version ownership was verified.', 'No fragment waiver was granted.'],
            'headSha' => $sha,
        ], get_object_vars($authorization));
    }

    public function testMissingHeadDefaultsToNullAndTheSnapshotCannotGainAnotherHead(): void
    {
        $authorization = new PullRequestAuthorization(false, false, 'ordinary', []);
        self::assertNull($authorization->headSha);
        self::assertFalse($authorization->waiverAuthorized);
        self::assertFalse($authorization->centralChangeAuthorized);
        $this->expectException(Error::class);

        $authorization->headSha = str_repeat('b', 40);
    }
}
