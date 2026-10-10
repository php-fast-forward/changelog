# GitHub transport boundary

`GitHubClientInterface::request($method, $relativePath, $body = null)` accepts
an uppercase HTTP method and an endpoint beginning with `/`. The injected API
base is an explicit HTTPS origin, optionally with an enterprise `/api/v3` path.
Credentials are supplied by composition; the adapter does not inspect host
environment, credential files, shell commands or `gh`.

Requests use `Accept: application/vnd.github+json`,
`X-GitHub-Api-Version: 2026-03-10`, and `User-Agent: FastForward-Changelog`.
An explicit nonempty token adds a Bearer header. Automatic redirects are disabled
so credentials cannot be forwarded to a different destination. JSON payloads
are passed through the HTTP client's `json` option.

A 404 or 204 response yields `null`; other non-success statuses produce a
controlled diagnostic containing only the HTTP status. Transport and decoding
errors produce a generic diagnostic without raw response data or a chained
exception. Successful responses return decoded JSON arrays.

`paginate($relativePath)` collects list records using `per_page=100` and numbered
pages. Caller pagination values are replaced. The `Link` header controls
continuation, but server-provided URLs are never used as request destinations.
Next links must stay within the trusted HTTPS API base and identify the next
numbered page of 100 items. Malformed lists, invalid links, unavailable list
endpoints and a page limit reached while another page is advertised fail
without returning partial records. The default limit is 100 pages.

[`Http\Factory\HttpClientFactory`](../Http/Factory/HttpClientFactory.php)
concentrates generic Symfony HTTP client construction.
`GitHubClientFactory` composes the transport, exception factory, explicit token
and API base without sending a request.

See the [SDK assessment](../../docs/integration-dependencies.md) for the verified
alternative libraries and the contracts an adoption would need to preserve.

Primary references verified on 2026-10-03:

- [GitHub API versions](https://docs.github.com/en/rest/about-the-rest-api/api-versions)
- [GitHub request headers](https://docs.github.com/en/rest/using-the-rest-api/getting-started-with-the-rest-api)
- [GitHub pagination](https://docs.github.com/en/rest/using-the-rest-api/using-pagination-in-the-rest-api)
- [Symfony HTTP client and response contracts](https://symfony.com/doc/current/http_client.html)
