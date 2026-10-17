<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use Error;
use FastForward\Changelog\Release\ReleaseReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseReceipt::class)]
final class ReleaseReceiptTest extends TestCase
{
    use ReceiptFixtureTrait;

    public function testRecoverySnapshotRetainsTheCompleteEvidenceAndExactPayloadBytes(): void
    {
        $data = ['id' => str_repeat('a', 64), ...self::evidence()];
        $receipt = new ReleaseReceipt($data);
        $data['consumed']['.changelog/later.md'] = str_repeat('b', 64);
        $data['notes'] = 'A later observation cannot replace approved recovery notes.';

        self::assertSame(['id' => str_repeat('a', 64), ...self::evidence()], $receipt->data);
        self::assertArrayNotHasKey('.changelog/later.md', $receipt->data['consumed']);
        self::assertSame(self::evidence()['notes'], $receipt->data['notes']);
        self::assertSame(self::evidence()['changelog_contents'], $receipt->data['changelog_contents']);
    }

    public function testRecoveryEvidenceCannotBeMutatedInPlace(): void
    {
        $receipt = new ReleaseReceipt(['id' => str_repeat('a', 64), ...self::evidence()]);
        $this->expectException(Error::class);

        $receipt->data['next_version'] = '9.0.0';
    }
}
