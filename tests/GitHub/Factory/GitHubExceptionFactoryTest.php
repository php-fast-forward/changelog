<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub\Factory;

use FastForward\Changelog\GitHub\Factory\GitHubExceptionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubExceptionFactory::class)]
final class GitHubExceptionFactoryTest extends TestCase
{
    #[Test]
    public function constructsOnlyTheControlledMessage(): void
    {
        $exception = new GitHubExceptionFactory()->failure('controlled diagnostic');
        self::assertSame('controlled diagnostic', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }
}
