<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub\Factory;

use FastForward\Changelog\GitHub\Factory\GitHubClientFactory;
use FastForward\Changelog\GitHub\Factory\GitHubExceptionFactoryInterface;
use FastForward\Changelog\GitHub\Factory\HttpClientFactoryInterface;
use FastForward\Changelog\GitHub\GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(GitHubClientFactory::class)]
#[UsesClass(GitHubClient::class)]
final class GitHubClientFactoryTest extends TestCase
{
    #[Test]
    public function constructsAdapterThroughInjectedFactoryWithoutSendingTraffic(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $clients = $this->createMock(HttpClientFactoryInterface::class);
        $clients->expects(self::once())->method('create')->willReturn($http);
        $errors = $this->createStub(GitHubExceptionFactoryInterface::class);
        self::assertInstanceOf(GitHubClient::class, new GitHubClientFactory($clients, $errors)->create('token', 'https://enterprise.test/api/v3'));
    }
}
