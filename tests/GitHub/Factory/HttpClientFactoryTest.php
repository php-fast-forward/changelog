<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub\Factory;

use FastForward\Changelog\GitHub\Factory\HttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(HttpClientFactory::class)]
final class HttpClientFactoryTest extends TestCase
{
    #[Test]
    public function constructsTransportWithoutStartingNetworkRequests(): void
    {
        self::assertInstanceOf(HttpClientInterface::class, new HttpClientFactory()->create());
    }
}
