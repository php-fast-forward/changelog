<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub;

use FastForward\Changelog\GitHub\GitHubClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubClient::class)]
final class GitHubClientTest extends TestCase
{
    /** Private acquisition authenticates canonical HTTPS only; no token enters the Git protocol body. */
    public function testCommitPackKeepsAuthenticationInHttpHeaders(): void
    {
        $sha = str_repeat('a', 40);
        $pack = 'PACK' . str_repeat('x', 28);
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (
            &$calls,
            $sha,
            $pack
        ): MockResponse {
            $calls[] = [$method, $url];
            self::assertSame([
                'Authorization: Basic ' . base64_encode('x-access-token:private-token')],
                $options['normalized_headers']['authorization'],
            );
            self::assertSame(0, $options['max_redirects']);
            self::assertStringNotContainsString('private-token', $url . ($options['body'] ?? ''));
            $options['on_progress'](32);
            if ('GET' === $method) {
                self::assertSame('https://github.com/owner/project.git/info/refs?service=git-upload-pack', $url);
                $bytes = "001e# service=git-upload-pack\n0000";
                $type = 'advertisement';
            } else {
                self::assertSame('https://github.com/owner/project.git/git-upload-pack', $url);
                $want = 'want ' . $sha . "\n";
                self::assertSame(sprintf('%04x', strlen($want) + 4) . $want . "00000009done\n", $options['body']);
                $bytes = "0008NAK\n" . $pack;
                $type = 'result';
            }

            return new MockResponse($bytes, ['response_headers' => ['content-type' => 'application/x-git-upload-pack-' . $type]]);
        });
        self::assertSame($pack, new GitHubClient($http, 'private-token')->commitPack('owner/project', $sha));
        self::assertCount(2, $calls);
    }

    /** Unverified identity, transport and credential inputs never make an HTTP request. */
    #[TestWith(['other', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'https://api.github.com', 'token'])]
    #[TestWith(['owner/project', 'main', 'https://api.github.com', 'token'])]
    #[TestWith(['owner/..', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'https://api.github.com', 'token'])]
    #[TestWith([
        'owner/project',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'https://enterprise.test/api/v3',
        'token',
    ])]
    #[TestWith(['owner/project', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'https://api.github.com', "bad\ntoken"])]
    public function testCommitPackRejectsUnverifiedInputs(string $repo, string $sha, string $api, string $token): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $this->expectExceptionMessage('verified public GitHub identities');
        new GitHubClient($http, $token, $api)->commitPack($repo, $sha);
    }

    /** Authentication denial, redirects and unexpected media cannot masquerade as an object pack. */
    #[TestWith([401, 'application/x-git-upload-pack-advertisement'])]
    #[TestWith([302, 'application/x-git-upload-pack-advertisement'])]
    #[TestWith([200, 'text/html'])]
    public function testCommitPackRefusesHttpFailure(int $status, string $type): void
    {
        $http = new MockHttpClient(new MockResponse('private-token raw error', [
            'http_code' => $status, 'response_headers' => ['content-type' => $type],
        ]));
        $this->expectExceptionMessage('HTTP evidence could not be verified');
        new GitHubClient($http, 'private-token')->commitPack('owner/project', str_repeat('a', 40));
    }

    /** Invalid framing is refused before credential-free bytes can reach a Git subprocess. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testCommitPackRefusesMalformedWireEvidence(bool $result): void
    {
        $responses = [];
        if ($result) {
            $responses[] = new MockResponse("001e# service=git-upload-pack\n0000", [
                'response_headers' => ['content-type' => 'application/x-git-upload-pack-advertisement'],
            ]);
        }
        $responses[] = new MockResponse('bad response', [
            'response_headers' => ['content-type' => 'application/x-git-upload-pack-' . ($result ? 'result' : 'advertisement')],
        ]);
        $this->expectExceptionMessage($result ? 'invalid complete pack' : 'invalid service discovery');
        new GitHubClient(new MockHttpClient($responses))->commitPack('owner/project', str_repeat('a', 40));
    }

    /** Transport exceptions must never disclose token-bearing request details. */
    public function testCommitPackRedactsTransportFailures(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('private-token request details');
        });
        $this->expectExceptionMessage('transport failed; details were withheld');
        new GitHubClient($http, 'private-token')->commitPack('owner/project', str_repeat('a', 40));
    }

    /** Download progress is capped before an oversized response can be retained. */
    public function testCommitPackRefusesOversizedDownloadProgress(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): never {
            $options['on_progress'](67_108_865);

            throw new RuntimeException('Unreachable');
        });
        $this->expectExceptionMessage('response limit');
        new GitHubClient($http)->commitPack('owner/project', str_repeat('a', 40));
    }

    /** A transport that omits progress still cannot return an oversized response. */
    public function testCommitPackRefusesOversizedResponse(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn(
            ['content-type' => ['application/x-git-upload-pack-advertisement']],
        );
        $response->method('getContent')->willReturn(str_repeat('x', 67_108_865));
        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturn($response);
        $this->expectExceptionMessage('response limit');
        new GitHubClient($http)->commitPack('owner/project', str_repeat('a', 40));
    }

    /** Git acquisition can only use public GitHub when API evidence shares that trusted origin. */
    public function testPublicTransportIdentityIsExplicit(): void
    {
        self::assertTrue(new GitHubClient($this->createStub(HttpClientInterface::class))->usesPublicGitHub());
        self::assertFalse(
            new GitHubClient($this->createStub(
                HttpClientInterface::class,
            ), apiUrl: 'https://enterprise.test/api/v3')->usesPublicGitHub(),
        );
    }

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

    /** Only the exact GraphQL POST leaves the REST v3 prefix, preserving the configured HTTPS origin. */
    #[Test]
    #[TestWith(['POST', '/graphql', 'https://enterprise.test/api/v3', 'https://enterprise.test/api/graphql'])]
    #[TestWith(['POST', '/graphql', 'https://enterprise.test/api/v3/', 'https://enterprise.test/api/graphql'])]
    #[TestWith([
        'POST',
        '/graphql',
        'https://enterprise.test/proxy/api/v3',
        'https://enterprise.test/proxy/api/graphql',
    ])]
    #[TestWith(['GET', '/graphql', 'https://enterprise.test/api/v3', 'https://enterprise.test/api/v3/graphql'])]
    #[TestWith([
        'POST',
        '/graphql?other=1',
        'https://enterprise.test/api/v3',
        'https://enterprise.test/api/v3/graphql?other=1',
    ])]
    #[TestWith(['POST', '/resource', 'https://enterprise.test/api/v3', 'https://enterprise.test/api/v3/resource'])]
    #[TestWith(['POST', '/graphql', 'https://enterprise.test/api/v30', 'https://enterprise.test/api/v30/graphql'])]
    #[TestWith(['POST', '/graphql', 'https://api.github.com', 'https://api.github.com/graphql'])]
    public function routesOnlyExactEnterpriseGraphqlPosts(
        string $method,
        string $path,
        string $api,
        string $expectedUrl,
    ): void {
        $body = 'POST' === $method ? ['query' => 'query { viewer { login } }'] : null;
        $options = ['headers' => ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10',
            'User-Agent' => 'FastForward-Changelog'], 'max_redirects' => 0];
        if (null !== $body) {
            $options['json'] = $body;
        }
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->with($method, $expectedUrl, $options)->willReturn(
            $this->response(200, []),
        );
        self::assertSame([], $this->client($http, '', $api)->request($method, $path, $body));
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
        return new GitHubClient($http, $token, $api, $maxPages);
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
