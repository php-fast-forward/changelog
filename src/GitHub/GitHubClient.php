<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\GitHub;

use FastForward\Changelog\GitHub\Factory\GitHubExceptionFactoryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Sends explicit GitHub API requests with bounded pagination and redacted failures. */
final readonly class GitHubClient implements GitHubClientInterface
{
    /**
     * Injects the transport and explicit API settings; no host or network state is read here.
     * The API version is pinned to the officially supported 2026-03-10 contract.
     * @see https://docs.github.com/en/rest/about-the-rest-api/api-versions
     */
    public function __construct(
        private HttpClientInterface $http,
        private GitHubExceptionFactoryInterface $exceptions,
        private string $token = '',
        private string $apiUrl = 'https://api.github.com',
        private int $maxPages = 100,
    ) {}

    /** Executes one request and returns only its decoded JSON value. */
    public function request(string $method, string $relativePath, ?array $body = null): ?array
    {
        return $this->exchange($method, $relativePath, $body)[0];
    }

    /**
     * Retrieves numbered list pages while refusing external pagination links and incomplete results.
     * GitHub's Link header controls continuation; its URL is validated but never followed.
     * @see https://docs.github.com/en/rest/using-the-rest-api/using-pagination-in-the-rest-api
     */
    public function paginate(string $relativePath): array
    {
        if ($this->maxPages < 1) {
            throw $this->exceptions->failure('GitHub pagination requires a positive page limit.');
        }
        $this->url($relativePath);
        $parts = explode('?', $relativePath, 2);
        parse_str($parts[1] ?? '', $query);
        $query['per_page'] = 100;
        $records = [];
        for ($page = 1; $page <= $this->maxPages; ++$page) {
            $query['page'] = $page;
            [$data, $headers] = $this->exchange('GET', $parts[0] . '?' . http_build_query($query));
            if (null === $data || ! array_is_list($data)) {
                throw $this->exceptions->failure('GitHub list endpoint is unavailable or returned an invalid list.');
            }
            foreach ($data as $record) {
                if (! is_array($record)) {
                    throw $this->exceptions->failure('GitHub list endpoint returned an invalid record.');
                }
                $records[] = $record;
            }
            if (! $this->hasNext($headers, $page)) {
                return $records;
            }
        }

        throw $this->exceptions->failure('GitHub pagination exceeded its page limit; no partial history was accepted.');
    }

    /**
     * Handles HTTP status before JSON decoding and drops raw transport error details.
     * @param  array<string,mixed>|null                     $body JSON request payload
     * @return array{0:?array,1:array<string,list<string>>} decoded data and controlled header access
     */
    private function exchange(string $method, string $relativePath, ?array $body = null): array
    {
        $url = $this->url($relativePath);
        if (1 !== preg_match('/\A[A-Z]+\z/', $method) || 1 === preg_match('/[\x00-\x20\x7f]/', $this->token)) {
            throw $this->exceptions->failure('GitHub method or credential settings are invalid.');
        }
        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2026-03-10', 'User-Agent' => 'FastForward-Changelog'];
        if ('' !== $this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        $options = ['headers' => $headers, 'max_redirects' => 0];
        if (null !== $body) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            if (404 === $status || 204 === $status) {
                return [null, []];
            }
            if ($status < 200 || $status >= 300) {
                throw $this->exceptions->failure('GitHub API request failed with HTTP ' . $status . '.');
            }

            return [$response->toArray(false), $response->getHeaders(false)];
        } catch (ExceptionInterface) {
            throw $this->exceptions->failure(
                'GitHub transport or JSON decoding failed; response details were withheld.',
            );
        }
    }

    /** Validates a relative endpoint and a HTTPS API base before credentials can be sent. */
    private function url(string $relativePath): string
    {
        $base = parse_url($this->apiUrl);
        $path = explode('?', rawurldecode($relativePath), 2)[0];
        if (! is_array($base) || 'https' !== ($base['scheme'] ?? null) || empty($base['host'])
            || 1 === preg_match(
                '/[\x00-\x20\x7f]/',
                $this->apiUrl,
            ) || isset($base['user']) || isset($base['query']) || isset($base['fragment'])
            || ! str_starts_with($relativePath, '/') || str_starts_with($relativePath, '//')
            || str_contains($relativePath, '\\') || str_contains($relativePath, '#')
            || 1 === preg_match('/[\x00-\x20\x7f]/', $relativePath)
            || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)
        ) {
            throw $this->exceptions->failure('GitHub API requests require a trusted HTTPS base and relative endpoint.');
        }

        return rtrim($this->apiUrl, '/') . $relativePath;
    }

    /**
     * Validates pagination origin without using server-supplied URLs as request destinations.
     * @param array<string,list<string>> $headers normalized response headers
     */
    private function hasNext(array $headers, int $page): bool
    {
        $link = implode(',', $headers['link'] ?? []);
        if (! str_contains($link, 'rel="next"')) {
            return false;
        }
        preg_match_all('/<([^>]+)>;\s*rel="next"/', $link, $matches);
        if (1 !== count($matches[1])) {
            throw $this->exceptions->failure('GitHub pagination returned an ambiguous next-page link.');
        }
        $next = parse_url($matches[1][0]);
        $base = parse_url($this->apiUrl);
        $basePath = rtrim($base['path'] ?? '', '/') . '/';
        if (! is_array($next) || 'https' !== ($next['scheme'] ?? null)
            || strtolower($next['host'] ?? '') !== strtolower($base['host'])
            || ($next['port'] ?? 443) !== ($base['port'] ?? 443)
            || isset($next['user']) || isset($next['fragment'])
            || ! str_starts_with($next['path'] ?? '', $basePath)
        ) {
            throw $this->exceptions->failure('GitHub pagination refused a link outside the trusted API base.');
        }
        parse_str($next['query'] ?? '', $query);
        if (($query['page'] ?? null) !== (string) ($page + 1) || ($query['per_page'] ?? null) !== '100') {
            throw $this->exceptions->failure('GitHub pagination returned an unsupported page sequence.');
        }

        return true;
    }
}
