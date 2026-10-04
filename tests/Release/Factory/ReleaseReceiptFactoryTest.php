<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Factory;

use FastForward\Changelog\Release\Factory\ReleaseReceiptFactory;
use FastForward\Changelog\Release\ReleaseReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseReceiptFactory::class)]
#[CoversClass(ReleaseReceipt::class)]
final class ReleaseReceiptFactoryTest extends TestCase
{
    /** Construction MUST retain the validated evidence exactly without any I/O. */
    public function testCapturesExactEvidence(): void
    {
        $data = ['id' => 'approved', 'notes' => "Exact  \n"];
        $receipt = new ReleaseReceiptFactory()->create($data);
        self::assertSame($data, $receipt->data);
    }
}
