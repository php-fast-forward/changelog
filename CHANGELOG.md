# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] - 2026-10-20

### Changed

- Include the GitHub repository and current-to-next version transition in generated version-PR titles by default, with repository placeholders and preserved custom titles. ([#60](https://github.com/php-fast-forward/changelog/pull/60))

### Fixed

- Restore changelog feedback for fork pull requests with Checkout v7 by explicitly allowing the exact head to be read as validation data, while keeping execution confined to the pinned trusted runtime and disabling persistent credentials, submodules and LFS. ([#61](https://github.com/php-fast-forward/changelog/pull/61))
- Preserve an explicitly selected minor or major impact in a canonical Dependabot fragment without rewriting it or refusing the rerun. Document the patch maintenance default and classify dependency updates by their effect on the consumer package's public contract. ([#62](https://github.com/php-fast-forward/changelog/pull/62))

## [0.3.0] - 2026-10-20

### Added

- Configure GitHub automation through a guided install command with a reviewed workflow preview and optional project skill, preserving existing integrations and installer ownership while showing installed and bundled skill versions separately. ([#58](https://github.com/php-fast-forward/changelog/pull/58))

### Changed

- Calculate the next version from the greatest stable committed changelog or ancestral tag, allowing later version PRs after an unpublished consolidation while preserving prior history and independent publication proofs. Generate new comparison links from reachable stable tags instead of untagged historical versions, preserving existing footer links. ([#54](https://github.com/php-fast-forward/changelog/pull/54))
- Enable publication after an approved version-PR merge by default, with explicit validation-only overrides, visible lifecycle summaries and a complete workflow and Action parameter reference. ([#54](https://github.com/php-fast-forward/changelog/pull/54))
- Runs package and documentation validation only when their inputs change, preserves required check contexts for release PRs, and records Windows integration timings. ([#57](https://github.com/php-fast-forward/changelog/pull/57))

### Fixed

- Confirm the requested version PR body before reporting a successful update after an uncertain API response, preventing an unchanged or missing body from being accepted as updated. ([#54](https://github.com/php-fast-forward/changelog/pull/54))
- Explains GitHub's public-repository event restriction and how administrators can review a narrowly scoped policy for changelog feedback and publication, distinguishing evaluated restrictions from failed jobs. ([#56](https://github.com/php-fast-forward/changelog/pull/56))

## [0.2.1] - 2026-10-18

### Fixed

- Explain which consolidated version still awaits its published tag when preparing another version PR, while keeping unrelated transport and collaborator errors redacted. ([#52](https://github.com/php-fast-forward/changelog/pull/52))

## [0.2.0] - 2026-10-18

### Added

- Adds explicit installation, integrity checks and guarded updates for the bundled Fast Forward Changelog skill through the CLI, including CPX usage. Local terminal warnings identify proven outdated native copies without network requests or automatic updates. The skill discovers the selected release's documentation and help so its core contribution instructions remain useful across releases. ([#50](https://github.com/php-fast-forward/changelog/pull/50))
- Maintains GitHub workflow channels as `v<major>` branches pointing to verified published stable releases. Channel updates preserve complete release tags, reject regressions and conflicting references, and support manual recovery with an exact release tag and commit SHA without adding apparent stable versions to Composer. ([#48](https://github.com/php-fast-forward/changelog/pull/48))

### Changed

- Makes GitHub adoption and local CPX execution easier to follow, separates skill installation from runtime and PHP integration, and organizes the guides by task while retaining detailed release, recovery and policy contracts. ([#47](https://github.com/php-fast-forward/changelog/pull/47))
- Includes the changelog package name and current-to-next version transition in this repository's automated release PR titles. ([#44](https://github.com/php-fast-forward/changelog/pull/44))

### Fixed

- Reports recognized GitHub error categories when a release-channel update is refused, while keeping server messages and authentication context out of logs. ([#49](https://github.com/php-fast-forward/changelog/pull/49))
- Uses the configured changelog token for released major branches during publication and manual recovery, allowing appropriately scoped credentials for historical commits with workflow changes. ([#51](https://github.com/php-fast-forward/changelog/pull/51))
- Keeps release image publication independent of GitHub Actions cache writes and supports explicit recovery from an existing published tag and its approved commit. ([#45](https://github.com/php-fast-forward/changelog/pull/45))

## [0.1.0] - 2026-10-17

### Added

- Reusable GitHub lifecycle for contribution checks, sticky PR feedback, verified Dependabot notes, optional GitHub App authentication and configurable Linux runners. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Independent Markdown fragments with validated metadata and semantic release impact, plus local and global CLI commands for authoring, checking, previewing and maintaining a project's changelog. Maintainers can curate pending notes through verified editorial PRs. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Self-contained Docker CLI and Docker Actions, with build and publication support for multi-platform GHCR release images. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- History adoption and maintenance that preserve existing Markdown and references, recognize Changesets headings, promote legacy Unreleased notes and support English, Brazilian Portuguese and trusted custom templates. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Injectable PHP service contracts, a PSR-11 service provider with optional Fast Forward Config integration and deterministic PSR-20 time for application integration. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Opt-in publication from the approved merged version PR, validating immutable commit, repository, transaction and release content before creating its tag and GitHub release. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- SemVer release references ordered with their sections, retained custom destinations, and automatic verified PR links in generated notes; explicit fragment metadata remains authoritative and no author or commit attribution is inferred. ([#43](https://github.com/php-fast-forward/changelog/pull/43))
- Portable fast-forward-changelog agent skill, consumer examples, focused guides and browsable phpDocumentor API documentation. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- One managed version PR with configurable titles, deterministic consolidation and recoverable transactions, updating readable history and removing consumed fragments without adding tracked plan files. ([#42](https://github.com/php-fast-forward/changelog/pull/42))

[0.4.0]: https://github.com/php-fast-forward/changelog/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/php-fast-forward/changelog/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/php-fast-forward/changelog/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/php-fast-forward/changelog/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/php-fast-forward/changelog/releases/tag/v0.1.0
