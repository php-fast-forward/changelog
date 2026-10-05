# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Bootstrap the standalone changelog domain, document model, manager, and reusable CLI commands (#1)
- Add a Git-baseline check command, explicit service contracts, a PSR-11 service provider, and lazy command loading (#1)
- Add deterministic PSR-20 time through `fast-forward/clock` and an isolated unit-test coverage gate (#1)

### Fixed

- Fix release ordering and version-prefix handling in changelog promotion, inference, and links (#1)
- Distinguish an absent baseline changelog from Git failures instead of accepting an unknown baseline (#1)
- Preserve CRLF documents, multiline list entries, nested bullets, and existing reference definitions during mutations (#1)
- Strip credentials from generated repository links and render release-note output without Symfony formatting (#1)
- Validate real release dates and create missing parent directories before Git repository discovery (#1)
- Treat prefixed versions as one identity and apply SemVer prerelease precedence when ordering releases (#1)

## [1.0.0] - 2026-10-05

### Changed

- Distribute the repository skill from one real GitHub package, with relative links for other hosts and explicit validation of Windows checkout behavior.
- Release pull requests now update readable Markdown history and remove consumed fragments without adding a tracked release plan. Recovery journals stay outside the versioned tree and are removed after successful consolidation.
- Adds a standalone fragment-based changelog and release lifecycle for Composer projects. The add/check/status/version/notes/publish/backfill/format commands replace the legacy Unreleased editing workflow, share their PHP domain with GitHub Actions, and support deterministic release plans, localized history, verified version pull requests and recoverable publication. This repository now records its own changes with the same CLI and consolidates CHANGELOG.md through its release workflow. ([@mentordosnerds](https://github.com/mentordosnerds))

### Fixed

- Accept GitHub platform-signed bot transactions while preserving exact author identity, signature, receipt and file-scope checks; expose safe commit diagnostics when ownership proof fails. ([@mentordosnerds](https://github.com/mentordosnerds))
- Separate release and category headings with blank lines while keeping simple changelog entries compact and preserving multiline Markdown.
- Reject ambiguous Unreleased headings and unsupported historical release headings before consolidation, so custom template changes cannot duplicate history or misidentify an existing release.
- Confirm refreshed version pull requests through a fresh read of the same pull request, so a stale GitHub update response cannot report a successful changelog transaction as failed.
