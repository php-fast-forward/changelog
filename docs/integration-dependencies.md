# Git and GitHub dependency assessment

Verified on 2026-10-09 against the lockfile, adapter contracts, unit tests and
Composer registry metadata and the published candidate versions below. This is
a static compatibility and
responsibility assessment; candidate SDKs were not installed or behaviorally
certified. The runtime already uses Symfony Process 8.1.7 and Symfony HttpClient
8.1.8 for process execution and HTTP. Keep those engines and the injected
`GitRepositoryInterface` and `GitHubClientInterface` boundaries for this release.

## Candidate decisions

| Candidate | Verified fit | Decision |
| --- | --- | --- |
| Symfony Process and HttpClient, already installed | Structured argv, process lifecycle, HTTP/JSON transport and replaceable collaborators. | Retain. Generic factories now live in `Process` and `Http`; Git/GitHub adapters express the caller's domain contract. |
| [gitonomy/gitlib 1.6.0](https://github.com/gitonomy/gitlib/blob/v1.6.0/composer.json) | Accepts Symfony Process 8, provides Git objects and uses the same process engine. Default discovery recognizes `.git` directories; its detached-HEAD parser expects 40 hexadecimal characters. | Compatible candidate, but worktree Git files and SHA-256 evidence need explicit adaptation. No demonstrated reduction of the changelog-specific adapter. |
| [czproject/git-php 4.6.0](https://github.com/czproject/git-php/tree/v4.6.0) | Its manifest supports PHP 8.0 through 8.5. It provides Git operations, a configurable runner and environment. Its CLI runner uses `proc_open`; its result offers normalized line output and exact string output. | Compatible with the current PHP 8.5 runtime, but its range would narrow the package's PHP 8.x allowance. Exact-byte reads, NUL records, credential policy and scoped `commit --only` remain integration responsibilities. |
| [knplabs/github-api 3.16.1](https://github.com/KnpLabs/php-github-api/tree/v3.16.1) | REST/GraphQL helpers, pagination and replaceable PSR-17/18 transport; Symfony can provide transport. Additional HTTP client/cache/discovery packages and a message/factory implementation are required. | A reasonable candidate for broader API use, but not a drop-in replacement for the current contract. Retain the bounded adapter until a focused prototype demonstrates a net gain. |

KnpLabs' [published paginator](https://github.com/KnpLabs/php-github-api/blob/v3.16.1/lib/Github/ResultPager.php)
follows the advertised next URL and iterates while another page exists. Our
contract checks the link's origin and page sequence, requests only constructed
relative endpoints, caps the inventory and rejects incomplete evidence. A future
SDK adapter must preserve those requirements rather than equating pagination
support with the same evidence contract.

CzProject's [runner](https://github.com/czproject/git-php/blob/v4.6.0/src/Runners/CliRunner.php)
accepts an environment, and its [result](https://github.com/czproject/git-php/blob/v4.6.0/src/RunnerResult.php)
exposes `getOutputAsString()` for raw bytes. `getOutput()` normalizes line endings
and removes terminal newlines. An implementation reading historical blobs must
choose the raw path explicitly. This observation does not establish a defect in
the library; it identifies an integration choice our exact-history contract needs.

Gitonomy's [1.6.0 repository implementation](https://github.com/gitonomy/gitlib/blob/v1.6.0/src/Gitonomy/Git/Repository.php)
allows explicit Git and working directories. Its automatic directory discovery
and detached-HEAD parser do not supply our complete worktree/SHA contract by
themselves. This is a static integration finding, not a claim that every API in
the library lacks support. Registry metadata was checked separately because
GitHub's latest published Release still pointed to 1.5.0; that older manifest's
Symfony ceiling must not be used to reject the current 1.6.0 package.

## Responsibilities that remain ours

An SDK can supply generic operations while the package retains:

- complete SHA identities, ancestry and exact committed blob bytes;
- tag/date provenance, symlink/submodule modes and NUL-delimited path records;
- preservation of unrelated index entries when committing one fragment;
- release trailers bound to the approved central history and source inventory;
- trusted API destinations, redacted failures and complete bounded pagination;
- one owned version-PR line, uncertain-write reconciliation and signed evidence;
- publication only from the approved commit, with create-only remote objects.

The existing Git adapter also reconstructs release trailers. That is
changelog-specific behavior, so a generic Git wrapper would not remove it.
Likewise, the observed stale GitHub read after a successful write is a transaction
confirmation concern; installing an API SDK does not by itself prove convergence.

For a later prototype, demonstrate these contracts with synthetic HTTP responses
and disposable Git repositories. Compare retained adapter code, dependencies and
error behavior. Preserve every existing safety and exact-history fixture, add
cases for any newly supported transport behavior, and use explicit retry policy
for reads and writes. Maintained dependencies improve their own responsibility;
their adoption still requires evidence for the package's contract.
