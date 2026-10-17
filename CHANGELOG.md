# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-17

### Added

- Reusable GitHub lifecycle for contribution checks, sticky PR feedback, verified Dependabot notes, optional GitHub App authentication and configurable Linux runners. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Independent Markdown fragments with validated metadata and semantic release impact, plus local and global CLI commands for authoring, checking, previewing and maintaining a project's changelog. Maintainers can curate pending notes through verified editorial PRs. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Self-contained Docker CLI and Docker Actions, with build and publication support for multi-platform GHCR release images. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- History adoption and maintenance that preserve existing Markdown and references, recognize Changesets headings, promote legacy Unreleased notes and support English, Brazilian Portuguese and trusted custom templates. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Injectable PHP service contracts, a PSR-11 service provider with optional Fast Forward Config integration and deterministic PSR-20 time for application integration. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Release/tag comparison links and verified discovery of missing fragment PR references, preserving explicit metadata without inventing author attribution or commit links. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Opt-in publication from the approved merged version PR, validating immutable commit, repository, transaction and release content before creating its tag and GitHub release. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- Portable fast-forward-changelog agent skill, consumer examples, focused guides and browsable phpDocumentor API documentation. ([#42](https://github.com/php-fast-forward/changelog/pull/42))
- One managed version PR with configurable titles, deterministic consolidation and recoverable transactions, updating readable history and removing consumed fragments without adding tracked plan files. ([#42](https://github.com/php-fast-forward/changelog/pull/42))

[0.1.0]: https://github.com/php-fast-forward/changelog/releases/tag/v0.1.0
