<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Policy\Factory;

use FastForward\Changelog\Automation\Policy\Factory\PullRequestAuthorizationFactory;
use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PullRequestAuthorizationFactory::class)]
#[CoversClass(PullRequestAuthorization::class)]
final class PullRequestAuthorizationFactoryTest extends TestCase
{
    #[Test]
    public function bindsAuthorityAndDiagnosticsToInspectedHead(): void
    {
        $value = new PullRequestAuthorizationFactory()->create(true, false, 'waiver', ['grant'], str_repeat('a', 40));
        self::assertTrue($value->waiverAuthorized);
        self::assertFalse($value->centralChangeAuthorized);
        self::assertSame('waiver', $value->kind);
        self::assertSame(['grant'], $value->diagnostics);
        self::assertSame(str_repeat('a', 40), $value->headSha);
        self::assertNull(new PullRequestAuthorizationFactory()->create(false, false, 'ordinary', [])->headSha);
    }
}
