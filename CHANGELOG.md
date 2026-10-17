# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-10-17

### Added

- Maintains GitHub workflow channels as `v<major>` branches pointing to verified published stable releases. Channel updates preserve complete release tags, reject regressions and conflicting references, and support manual recovery with an exact release tag and commit SHA without adding apparent stable versions to Composer. ([#48](https://github.com/php-fast-forward/changelog/pull/48))

### Changed

- Makes GitHub adoption and local CPX execution easier to follow, separates skill installation from runtime and PHP integration, and organizes the guides by task while retaining detailed release, recovery and policy contracts. ([#47](https://github.com/php-fast-forward/changelog/pull/47))
- Includes the changelog package name and current-to-next version transition in this repository's automated release PR titles. ([#44](https://github.com/php-fast-forward/changelog/pull/44))

### Fixed

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

[0.2.0]: https://github.com/php-fast-forward/changelog/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/php-fast-forward/changelog/releases/tag/v0.1.0
