# Source namespaces

Namespaces follow the responsibility expressed by a type's contract. A changeset
value, a release transaction and a GitHub automation policy remain in their own
domains. Reusable transport construction belongs to its engine boundary.
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
| `Container\ServiceProvider` | Lazy composition and consumer configuration. |
| `Date` | Explicit timezone construction; date validity rules live in `Validator`. |
| `Filesystem` | Managed file access, canonical package paths and Finder construction. |
| `Fragment` | Contributor-facing creation, unique identifiers and optional scoped commits. |
| `Git` | Repository observations and exact Git command results needed by changelog services. |
| `GitHub` | Authenticated GitHub API adaptation with bounded complete evidence and controlled diagnostics. |
| `Http` | Generic Symfony HTTP transport construction, without GitHub credentials or endpoints. |
| `History` | Maintained Markdown, release values and historical import. |
| `Process` | Structured process construction and credential isolation, independent of Git command semantics. |
| `Publication` | Approved-commit publication evidence and create-only tag/release reconciliation. |
| `Release` | Exact plans, receipts, journals, consolidation and application; `Renderer` owns release-note presentation. |
| `Template` | Validated localized headings and explicit custom presentation. |
| `Validation` | Contribution-check orchestration and its aggregate report. |
| `Validator` | Changelog-specific date, fragment and committed-evidence rules. |
| `Version` | Semantic impact, aggregation, next-version calculation and installed-version resolution. |

`Validation` describes the contribution operation and result; `Validator`
contains the rules used by it and other operations. Shared values stay with the
domain they describe, rather than in a generic model or utility namespace.
Factory subnamespaces are local construction boundaries, not separate domains.

## PHP API migration

The namespace correction changes public PHP names and carries a major fragment.
Use the new imports and container service keys. CLI names, flags, JSON, Markdown
and release evidence remain unchanged. No compatibility aliases are introduced.

| Previous namespace/type | Canonical namespace/type |
| --- | --- |
| `Changeset\VersionImpact` | `Version\VersionImpact` |
| `Console\GitHubOutputWriter` and `GitHubOutputWriterInterface` | `Automation\Output\GitHubOutputWriter` and `GitHubOutputWriterInterface` |
| `Git\Factory\ProcessFactory` and `ProcessFactoryInterface` | `Process\Factory\ProcessFactory` and `ProcessFactoryInterface` |
| `GitHub\Factory\HttpClientFactory` and `HttpClientFactoryInterface` | `Http\Factory\HttpClientFactory` and `HttpClientFactoryInterface` |
| `Release\ReleaseNotesRenderer` and `ReleaseNotesRendererInterface` | `Release\Renderer\ReleaseNotesRenderer` and `ReleaseNotesRendererInterface` |

The audit inspected all 169 source declarations and their contracts. It moves
nine declarations and five corresponding unit-test files, without adding runtime
types or changing method bodies. Source and tests continue to share the single
root [development contract](../AGENTS.md); these ordinary folders need no child
instruction files.

See the [Git/GitHub dependency assessment](integration-dependencies.md) for engine
reuse and the [development scripts guide](../scripts/README.md) for maintenance
tools outside the production CLI.
