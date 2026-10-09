<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub;

use FastForward\Changelog\GitHub\Factory\GitHubExceptionFactoryInterface;
use FastForward\Changelog\GitHub\GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubClient::class)]
final class GitHubClientTest extends TestCase
{
    #[Test]
    public function sendsExplicitHeadersJsonAndDisablesRedirects(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->with(
            'POST',
            'https://api.github.com/repos/owner/project/issues',
            [
                'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10', 'User-Agent' => 'FastForward-Changelog', 'Authorization' => 'Bearer private-token'],
                'max_redirects' => 0, 'json' => ['title' => 'Example'],
            ],
        )->willReturn($this->response(201, ['number' => 3]));
        self::assertSame([
            'number' => 3],
            $this->client(
                $http,
                'private-token',
            )->request('POST', '/repos/owner/project/issues', ['title' => 'Example']),
        );
    }

    #[Test]
    public function supportsAnonymousEnterpriseApiWithoutReadingEnvironment(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->with('GET', 'https://enterprise.test/api/v3/meta', [
            'headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10', 'User-Agent' => 'FastForward-Changelog'], 'max_redirects' => 0,
        ])->willReturn($this->response(200, []));
        self::assertSame([], $this->client($http, '', 'https://enterprise.test/api/v3/')->request('GET', '/meta'));
    }

    #[Test]
    #[TestWith([404])]
    #[TestWith([204])]
    public function absentOrNoContentResponseReturnsNullWithoutDecoding(int $status): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->expects(self::never())->method('toArray');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($response);
        self::assertNull($this->client($http)->request('DELETE', '/resource'));
    }

    #[Test]
    #[TestWith([301])]
    #[TestWith([403])]
    #[TestWith([429])]
    #[TestWith([500])]
    public function httpErrorsRetainStatusAndExcludeSecrets(int $status): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($this->response($status));
        try {
            $this->client($http, 'super-secret')->request('GET', '/resource?secret=super-secret');
            self::fail('Expected a diagnostic.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('HTTP ' . $status, $error->getMessage());
            self::assertStringNotContainsString('super-secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    #[Test]
    public function transportExceptionsAreRedactedWithoutChaining(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willThrowException(
            new TransportException('super-secret raw server error'),
        );
        $this->expectExceptionMessage('response details were withheld');
        $this->client($http)->request('GET', '/resource');
    }

    #[Test]
    public function invalidJsonProducesAnApplicationDiagnostic(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->expects(self::once())->method('toArray')->with(false)->willThrowException(
            new JsonException('secret raw JSON'),
        );
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($response);
        $this->expectExceptionMessage('response details were withheld');
        $this->client($http)->request('GET', '/resource');
    }

    #[Test]
    #[TestWith(['get', '/resource', '', 'https://api.github.com'])]
    #[TestWith(['GET', '/resource', "token\nsecret", 'https://api.github.com'])]
    #[TestWith(['GET', 'https://evil.test/resource', '', 'https://api.github.com'])]
    #[TestWith(['GET', '//evil.test/resource', '', 'https://api.github.com'])]
    #[TestWith(['GET', '/resource#fragment', '', 'https://api.github.com'])]
    #[TestWith(['GET', '/resource with spaces', '', 'https://api.github.com'])]
    #[TestWith(['GET', '/../resource', '', 'https://api.github.com'])]
    #[TestWith(['GET', '/%2e%2e/resource', '', 'https://api.github.com'])]
    #[TestWith(['GET', '/resource\\evil', '', 'https://api.github.com'])]
    #[TestWith(['GET', '/resource', '', 'http://api.github.com'])]
    #[TestWith(['GET', '/resource', '', 'https://user:secret@api.github.com'])]
    #[TestWith(['GET', '/resource', '', 'https://api.github.com?secret=value'])]
    #[TestWith(['GET', '/resource', '', 'https://api.github.com#fragment'])]
    #[TestWith(['GET', '/resource', '', 'https://api.github.com:bad'])]
    public function rejectsUnsafeSettingsBeforeTransport(
        string $method,
        string $path,
        string $token,
        string $base,
    ): void {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $this->expectException(RuntimeException::class);
        $this->client($http, $token, $base)->request($method, $path);
    }

    #[Test]
    public function collectsAllPagesAndOverridesCallerPagingWithoutFollowingHeaderPath(): void
    {
        $first = array_fill(0, 100, ['id' => 1]);
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturnCallback(function (string $method, string $url) use (
            $first
        ): ResponseInterface {
            self::assertSame('GET', $method);
            if (str_ends_with($url, 'page=1')) {
                self::assertSame(
                    'https://api.github.com/repos/owner/project/releases?state=all&per_page=100&page=1',
                    $url,
                );

                return $this->response(
                    200,
                    $first,
                    ['link' => ['<https://api.github.com/repositories/123/releases?per_page=100&page=2>; rel="next"']],
                );
            }
            self::assertSame('https://api.github.com/repos/owner/project/releases?state=all&per_page=100&page=2', $url);

            return $this->response(
                200,
                [['id' => 2]],
                ['link' => ['<https://api.github.com/resource?page=1>; rel="prev"']],
            );
        });
        self::assertSame([
            ...$first, ['id' => 2]],
            $this->client($http)->paginate('/repos/owner/project/releases?state=all&per_page=5&page=9'),
        );
    }

    #[Test]
    #[TestWith([null, 404])]
    #[TestWith([['message' => 'not a list'], 200])]
    #[TestWith([['invalid record'], 200])]
    public function unavailableOrInvalidListsCannotBecomeEmptyHistory(?array $data, int $status): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($this->response($status, $data ?? []));
        $this->expectException(RuntimeException::class);
        $this->client($http)->paginate('/releases');
    }

    #[Test]
    #[TestWith(['<https://evil.test/releases?per_page=100&page=2>; rel="next"'])]
    #[TestWith(['<http://api.github.com/releases?per_page=100&page=2>; rel="next"'])]
    #[TestWith(['<https://user@api.github.com/releases?per_page=100&page=2>; rel="next"'])]
    #[TestWith(['<https://api.github.com:444/releases?per_page=100&page=2>; rel="next"'])]
    #[TestWith(['<https://api.github.com/releases?per_page=100&page=2#fragment>; rel="next"'])]
    #[TestWith(['not-a-link; rel="next"'])]
    #[TestWith([
        '<https://api.github.com/releases?per_page=100&page=2>; rel="next", <https://api.github.com/releases?per_page=100&page=2>; rel="next"',
    ])]
    #[TestWith(['<https://api.github.com/releases?per_page=100&page=8>; rel="next"'])]
    public function rejectsUnsafeOrAmbiguousPaginationWithoutASecondRequest(string $link): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(
            $this->response(200, [['id' => 1]], ['link' => [$link]]),
        );
        $this->expectException(RuntimeException::class);
        $this->client($http)->paginate('/releases');
    }

    #[Test]
    public function enterprisePaginationCannotLeaveItsApiPath(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(
            $this->response(200, [], ['link' => [
                '<https://enterprise.test/api/v30/releases?per_page=100&page=2>; rel="next"',
            ]]),
        );
        $this->expectExceptionMessage('outside the trusted API base');
        $this->client($http, '', 'https://enterprise.test/api/v3')->paginate('/releases');
    }

    #[Test]
    #[TestWith([0])]
    #[TestWith([1])]
    public function pageLimitsFailClosedWithoutReturningPartialRecords(int $limit): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(0 === $limit ? self::never() : self::once())->method('request')->willReturn(
            $this->response(200, [['id' => 1]], ['link' => [
                '<https://api.github.com/releases?per_page=100&page=2>; rel="next"',
            ]]),
        );
        $this->expectException(RuntimeException::class);
        $this->client($http, '', 'https://api.github.com', $limit)->paginate('/releases');
    }

    private function client(
        HttpClientInterface $http,
        string $token = '',
        string $api = 'https://api.github.com',
        int $maxPages = 100,
    ): GitHubClient {
        $errors = $this->createStub(GitHubExceptionFactoryInterface::class);
        $errors->method('failure')->willReturnCallback(
            static fn(string $message): RuntimeException => new RuntimeException($message),
        );

        return new GitHubClient($http, $errors, $token, $api, $maxPages);
    }

    private function response(int $status, array $data = [], array $headers = []): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('toArray')->willReturn($data);
        $response->method('getHeaders')->willReturn($headers);

        return $response;
    }
}
