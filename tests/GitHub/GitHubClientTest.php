<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\GitHub;

use FastForward\Changelog\GitHub\GitHubClient;
use FastForward\Changelog\GitHub\GitHubRetryPolicy;
use FastForward\Changelog\GitHub\GitHubTransientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubClient::class)]
#[UsesClass(GitHubRetryPolicy::class)]
#[UsesClass(GitHubTransientException::class)]
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

    /**
     * Exercises second/third-attempt recovery and exhaustion without real delays or hidden extra transport calls.
     *
     * @param  int  $successAttempt Intended successful attempt within the fixed three-request budget.
     * @param  bool $exhaust        Whether every connection attempt fails instead of returning data.
     * @return void Request and recorded wait counts prove the bounded retry contract.
     */
    #[TestWith([2, false])]
    #[TestWith([3, false])]
    #[TestWith([3, true])]
    public function testReadTransportRecoveryIsBounded(int $successAttempt, bool $exhaust): void
    {
        $count = 0;
        $waits = [];
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly($successAttempt))->method('request')->willReturnCallback(
            function () use (&$count, $successAttempt, $exhaust): ResponseInterface {
                ++$count;

                if ($exhaust || $count < $successAttempt) {
                    throw new TransportException('synthetic secret that must not escape');
                }

                return $this->response(200, ['verified' => true]);
            },
        );
        $client = new GitHubClient($http, retries: new GitHubRetryPolicy(static function (int $delay) use (
            &$waits
        ): void {
            $waits[] = $delay;
        }));

        try {
            self::assertSame(['verified' => true], $client->request('GET', '/resource'));
            self::assertFalse($exhaust);
        } catch (GitHubTransientException $error) {
            self::assertTrue($exhaust);
            self::assertStringNotContainsString('synthetic secret', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(array_slice([1_000_000, 2_000_000], 0, $successAttempt - 1), $waits);
    }

    /**
     * Retries only explicitly transient HTTP statuses, retaining a bounded numeric server delay.
     *
     * @param  int  $status Simulated transient HTTP status from the first response.
     * @return void The successful second read and recorded delay prove classification and timing.
     */
    #[TestWith([429])]
    #[TestWith([500])]
    #[TestWith([503])]
    #[TestWith([599])]
    public function testReadHttpRecoveryHonorsRetryAfter(int $status): void
    {
        $waits = [];
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturn(
            $this->response($status, headers: ['retry-after' => ['3']]), $this->response(200, []),
        );
        $client = new GitHubClient($http, retries: new GitHubRetryPolicy(static function (int $delay) use (
            &$waits
        ): void {
            $waits[] = $delay;
        }));
        self::assertSame([], $client->request('GET', '/resource'));
        self::assertSame([3_000_000], $waits);
    }

    /**
     * Never shortens a long server delay or guesses a wall-clock value for an unsupported date.
     *
     * @param  string $after Server Retry-After header that cannot fit the bounded delta-second policy.
     * @return void   A single transport call and absent sleep prove safe refusal.
     */
    #[TestWith(['31'])]
    #[TestWith(['999999999999999999999999'])]
    #[TestWith(['Wed, 21 Oct 2026 07:28:00 GMT'])]
    #[TestWith(['invalid'])]
    public function testUnsupportedRetryAfterStopsWithoutWaiting(string $after): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn(
            $this->response(429, headers: ['retry-after' => [$after]]),
        );
        $client = new GitHubClient($http, retries: new GitHubRetryPolicy(static function (): void {
            self::fail('An unsupported server delay must not sleep.');
        }));
        $this->expectException(GitHubTransientException::class);
        $client->request('GET', '/resource');
    }

    /**
     * Requires a positively identified query document before replaying any GraphQL POST.
     *
     * @param  mixed $query Explicit query payload; shorthand, malformed and mutating documents remain non-retryable.
     * @param  bool  $safe  Whether the conservative query-only recognition permits another attempt.
     * @return void  Request counts distinguish read recovery from forbidden mutation replay.
     */
    #[TestWith(['query Example { viewer { login } }', true])]
    #[TestWith(['query { viewer { login } }', true])]
    #[TestWith(['mutation Example { createRef { clientMutationId } }', false])]
    #[TestWith(['query Example { viewer { login } } mutation Other { createRef { clientMutationId } }', false])]
    #[TestWith(['{ viewer { login } }', false])]
    #[TestWith([null, false])]
    public function testGraphqlRetriesOnlyPositiveReadDocuments(mixed $query, bool $safe): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly($safe ? 2 : 1))->method('request')->willReturn(
            $this->response(503), $this->response(200, ['data' => []]),
        );
        $client = new GitHubClient($http, retries: new GitHubRetryPolicy(static function (): void {}));

        if (! $safe) {
            $this->expectException(GitHubTransientException::class);
        }
        self::assertSame(['data' => []], $client->request('POST', '/graphql', ['query' => $query]));
    }

    /**
     * Keeps authorization, validation and JSON failures outside automatic recovery even when a policy is installed.
     *
     * @param  int  $status Permanent HTTP response or a success response with invalid JSON.
     * @return void The response is inspected once and no sleeper or second request is invoked.
     */
    #[TestWith([401])]
    #[TestWith([403])]
    #[TestWith([409])]
    #[TestWith([422])]
    #[TestWith([200])]
    public function testPermanentAndJsonErrorsNeverRetry(int $status): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('toArray')->willThrowException(new JsonException('private invalid JSON'));
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($response);
        $client = new GitHubClient($http, retries: new GitHubRetryPolicy(static function (): void {
            self::fail('A permanent or JSON error must not sleep.');
        }));
        $this->expectException(RuntimeException::class);
        $client->request('GET', '/resource');
    }

    /**
     * Leaves mutation replay exclusively to the owning service after exact reconciliation.
     *
     * @return void An ordinary REST write is attempted once despite an installed read retry policy.
     */
    public function testRestMutationIsNeverReplayedByTheTransport(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($this->response(503));
        $client = new GitHubClient($http, retries: new GitHubRetryPolicy(static function (): void {
            self::fail('The HTTP adapter cannot authorize another write.');
        }));
        $this->expectException(GitHubTransientException::class);
        $client->request('POST', '/repos/owner/project/pulls', ['title' => 'Example']);
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
