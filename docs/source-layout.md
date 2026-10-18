# Source namespaces

Use this reference when integrating PHP services or migrating an application
from an earlier source snapshot. Start with [PHP adoption](adoption.md) for the
complete recipe, then locate its contracts in the map below.

Namespaces follow the responsibility expressed by a type's contract. A changeset
value, a release transaction and a GitHub automation policy remain in their own
domains. The composition root selects shared HTTP transport, secure randomness
and timezone defaults; operational factories retain dynamic process, configuration
source and filesystem-discovery construction.
Interfaces, implementations and their unit tests move together. PSR-4 spelling
and filesystem case must match; Composer's strict optimized loader and Linux CI
verify that structural contract.

## Responsibility map

| Namespace below `FastForward\Changelog` | Responsibility |
| --- | --- |
| `Automation` | Trusted check, Dependabot, version-PR and publication orchestration; `Policy` owns authorization evidence and `Output` owns GitHub Actions output files. |
| `Changeset` | Fragment content values, categories, parsing, serialization and domain storage. |
| `Configuration` | Lazy custom PHP configuration-source construction. |
| `Console` | Symfony application, command binding, input DTOs, terminal normalization, output and exit codes. |
| `Container\ServiceProvider` | Lazy composition, consumer configuration and explicit transport, entropy and timezone defaults. |
| `Filesystem` | Managed file access, canonical package paths and Finder construction. |
| `Fragment` | Contributor-facing creation, unique identifiers and optional scoped commits. |
| `Git` | Repository observations and exact Git command results needed by changelog services. |
| `GitHub` | Authenticated GitHub API adaptation with bounded complete evidence and controlled diagnostics. |
| `History` | Maintained Markdown, release values and historical import. |
| `Process` | Structured process construction and credential isolation, independent of Git command semantics. |
| `Publication` | Approved-commit publication evidence and create-only tag/release reconciliation. |
| `Release` | Exact plans, receipts, journals, consolidation and application; `Renderer` owns release-note presentation. |
| `Skill` | Local installation, integrity checks and explicit updates of the one canonical bundled skill; native ownership and recovery evidence, without remote discovery. |
| `Template` | Validated localized headings and explicit custom presentation. |
| `Validation` | Contribution-check orchestration and its aggregate report. |
| `Validator` | Changelog-specific date, fragment and committed-evidence rules. |
| `Version` | Semantic impact, aggregation, next-version calculation and installed-version resolution. |

`Validation` describes the contribution operation and result; `Validator`
contains the rules used by it and other operations. Shared values stay with the
domain they describe, rather than in a generic model or utility namespace.
Factory subnamespaces are local construction boundaries, not separate domains.

`Release\Reference` enriches pending notes with verified merged pull-request
references. `Git\GitFragmentOriginReaderInterface` is an optional capability of
Git adapters: custom adapters need it only when automatic references are enabled.
It reads immutable committed history rather than the current worktree or index.
The standard provider wires both contracts automatically.

Direct PHP construction of `Release\ReleasePlanner` and
`Validator\PublicationEvidenceValidator` now requires a
`Release\Reference\PullRequestReferenceResolverInterface` collaborator before
their optional arguments. Consumers constructing these services manually must
supply it; consumers using `ChangelogServiceProvider` need no wiring change.

## PHP API migration

The namespace cleanup changes public PHP names before the first public release,
`0.1.0`. These mappings help applications that adopted earlier development
commit pins move to the release API. Use the new imports and container service
keys. Existing CLI names, long options,
JSON, Markdown and release evidence remain unchanged. The additional short
options are documented in the [CLI reference](cli-fragments.md). No PHP compatibility aliases
are introduced.

| Previous namespace/type | Canonical namespace/type |
| --- | --- |
| `Changeset\VersionImpact` | `Version\VersionImpact` |
| `Console\GitHubOutputWriter` and `GitHubOutputWriterInterface` | `Automation\Output\GitHubOutputWriter` and `GitHubOutputWriterInterface` |
| `Git\Factory\ProcessFactory` and `ProcessFactoryInterface` | `Process\Factory\ProcessFactory` and `ProcessFactoryInterface` |
| `GitHub\Factory\HttpClientFactory` and `HttpClientFactoryInterface` | HTTP transport is now composed through `Symfony\Contracts\HttpClient\HttpClientInterface`; see the factory removal migration below |
| `Release\ReleaseNotesRenderer` and `ReleaseNotesRendererInterface` | `Release\Renderer\ReleaseNotesRenderer` and `ReleaseNotesRendererInterface` |

Source declarations, composition keys and corresponding unit-test namespaces
use these canonical names together. Source and tests share the single root
[development contract](https://github.com/php-fast-forward/changelog/blob/main/AGENTS.md); these ordinary folders need no child
instruction files.

## Factory removal migration

This is a deliberate PHP API break between pre-publication development
snapshots, before the first public release, `0.1.0`. The following 43 public
symbols were removed: 22 concrete factories and their 21 interfaces.
They existed in earlier main-branch snapshots and were exposed through the provider;
applications using their FQCNs, container keys or constructor arguments must migrate.
No compatibility aliases retain the removed contracts. CLI arguments, fragment
schema and persisted release evidence retain their existing contracts.

All names in this table are relative to `FastForward\Changelog\`. Each row removes
the named class and its corresponding `Interface` suffix, except `TimezoneFactory`.

| Removed factory | Replacement |
| --- | --- |
| `Changeset\Factory\ChangesetFactory` | `new Changeset\Changeset(...)` |
| `Changeset\Factory\ChangesetParseResultFactory` | `new Changeset\ChangesetParseResult($id, $changeset, $errors)` |
| `Validation\Factory\ValidationReportFactory` | `new Validation\ValidationReport(...)` |
| `Version\Factory\VersionResolutionFactory` | `new Version\VersionResolution(...)` |
| `Release\Factory\ReleaseReceiptFactory` | `new Release\ReleaseReceipt($validatedData)` |
| `History\Import\Factory\HistoryImportResultFactory` | `new History\Import\HistoryImportResult(...)` |
| `Publication\Factory\PublicationEvidenceFactory` | `new Publication\PublicationEvidence(...)` |
| `Publication\Factory\PublicationResultFactory` | `new Publication\PublicationResult(...)` |
| `Automation\Dependabot\Factory\DependabotFragmentResultFactory` | `new Automation\Dependabot\DependabotFragmentResult(...)` |
| `Automation\Policy\Factory\PullRequestAuthorizationFactory` | `new Automation\Policy\PullRequestAuthorization(...)` |
| `Automation\VersionPullRequest\Factory\VersionPullRequestResultFactory` | `new Automation\VersionPullRequest\VersionPullRequestResult(...)` |
| `Filesystem\Factory\PathExceptionFactory` | `new \InvalidArgumentException(...)`, retaining the path diagnostic |
| `Fragment\Factory\FragmentExceptionFactory` | `new \InvalidArgumentException(...)` or `new \RuntimeException(...)` |
| `GitHub\Factory\GitHubExceptionFactory` | `new \RuntimeException(...)` |
| `History\Factory\HistoryExceptionFactory` | `new \InvalidArgumentException(...)` |
| `Release\Factory\ReleaseExceptionFactory` | `new \InvalidArgumentException(...)` or `new \RuntimeException(...)` |
| `Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactory` | `new Automation\VersionPullRequest\VersionPullRequestException(...)` |
| `Date\Factory\TimezoneFactory` (class only) | `new \DateTimeZone('UTC')` in composition |
| `Fragment\Factory\IdentifierGeneratorFactory` | `Fragment\IdentifierGeneratorInterface` resolves to `Fragment\RandomIdentifierGenerator`, with an injected `\Random\Randomizer` |
| `GitHub\Factory\GitHubClientFactory` | `GitHub\GitHubClientInterface` resolves to `GitHub\GitHubClient`, with injected HTTP transport and explicit token/API settings |
| `Http\Factory\HttpClientFactory` | Compose `Symfony\Contracts\HttpClient\HttpClientInterface` using `Symfony\Component\HttpClient\HttpClient::create()` |
| `Console\CommandLoader\Factory\LazyCommandFactory` | `Console\CommandLoader\ChangelogCommandLoader` constructs and caches its lazy wrappers |

Direct value construction does not require a container entry or injected factory:

```php
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;

$changeset = new Changeset(
    id: 'parser-spaces.md',
    category: Category::Fixed,
    issue: null,
    pullRequest: null,
    author: null,
    description: 'Preserves meaningful Markdown spaces.',
);
```

Parsing still validates fragment metadata before constructing that value. When
replacing exception factories, preserve the exact exception type, message, zero
code and previous exception; use `previous: $error` rather than passing it as the
standard constructor's second argument.

The following public constructors lose the listed arguments. Remaining arguments
keep their names, order and defaults. Constructors with no remaining dependencies
are removed, so those classes are constructed without arguments.

| Class | Removed constructor arguments |
| --- | --- |
| `Changeset\Parser\ChangesetParser` | `$changesetFactory`, `$resultFactory` (no dependencies remain) |
| `Changeset\Store\FilesystemChangesetStore` | `$exceptionFactory` |
| `Validator\ChangesetValidator` | `$reportFactory` |
| `Validation\CheckService` | `$reports` |
| `Version\NextVersionResolver` | `$resultFactory` |
| `Fragment\FragmentWriter` | `$exceptions` |
| `Filesystem\ManagedFileStore` | `$exceptions` |
| `History\HistoryCodec` | `$exceptionFactory` |
| `History\Import\HistoryImporter` | `$results`, `$exceptions` |
| `Release\ReceiptCodec` | `$receipts`, `$exceptions` (no dependencies remain) |
| `Release\ReleaseMetadataParser` | `$exceptions` (no dependencies remain) |
| `Release\ReleaseApplier` | `$exceptions` |
| `Release\ReleaseHistoryConsolidator` | `$exceptions` |
| `Release\ReleasePlanner` | `$exceptions` |
| `Template\TemplateResolver` | `$exceptions` |
| `Git\GitRepository` | `$exceptions`; retains `$processes` and optional `$metadata` |
| `GitHub\GitHubClient` | `$exceptions`; retains `$http`, `$token`, `$apiUrl`, `$maxPages` |
| `Publication\PublicationService` | `$results`, `$exceptions` |
| `Validator\PublicationEvidenceValidator` | `$evidence`, `$exceptions` |
| `Validator\ReleaseInputEvidenceValidator` | `$exceptions` |
| `Automation\AutomationRunner` | `$exceptions` |
| `Automation\Policy\PullRequestPolicy` | `$results` |
| `Automation\Dependabot\DependabotFragmentService` | `$changesets`, `$results` |
| `Automation\Dependabot\Factory\DependabotInputFactory` | `$exceptions` (no dependencies remain) |
| `Automation\VersionPullRequest\VersionPullRequestService` | `$results`, `$exceptions` |
| `Automation\VersionPullRequest\Factory\VersionPullRequestInputFactory` | `$exceptions` (no dependencies remain) |
| `Console\CommandLoader\ChangelogCommandLoader` | `$factory`; retains only `$container` |

Factories with behavior remain: `DependabotInputFactory`,
`VersionPullRequestInputFactory`, `HistoryDocumentFactory`, `HistoryReleaseFactory`,
`ReleaseOptionsFactory`, `ReleasePlanFactory` and `TemplateFactory` validate or
normalize input, construct canonical evidence or preserve historical invariants.
Their interfaces remain available. `ProcessFactory`, `ConfigSourceFactory` and
`FinderFactory` also retain their interfaces for dynamic operational products.
Application-facing services such as `FragmentWriterInterface`,
`ChangesetParserInterface`, `ReleasePlannerInterface` and `GitHubClientInterface`
continue to provide the behavior contracts. The [PHP adoption example](adoption.md)
still uses `ReleaseOptionsFactoryInterface` to validate consumer settings.

See the [Git/GitHub dependency assessment](integration-dependencies.md) for engine
reuse and the [development scripts guide](https://github.com/php-fast-forward/changelog/blob/main/scripts/README.md) for maintenance
tools outside the production CLI.

---

[Documentation index](index.md)
