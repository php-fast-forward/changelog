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
<!-- fast-forward-changelog:release {"version":"1.0.0","date":"2026-10-04","date_source":"release-plan","body_length":1269} -->
## [1.0.0] - 2026-10-04
<!-- fast-forward-changelog:category changed -->
### Changed

<!-- fast-forward-changelog:fragment {"id":"standalone-release-lifecycle.md","category":"changed","type":"major","issue":null,"pull_request":null,"author":"mentordosnerds"} -->
- Adds a standalone fragment-based changelog and release lifecycle for Composer projects. The add/check/status/version/notes/publish/backfill/format commands replace the legacy Unreleased editing workflow, share their PHP domain with GitHub Actions, and support deterministic release plans, localized history, verified version pull requests and recoverable publication. This repository now records its own changes with the same CLI and consolidates CHANGELOG.md through its release workflow. ([@mentordosnerds](https://github.com/mentordosnerds))

<!-- fast-forward-changelog:category fixed -->
### Fixed

<!-- fast-forward-changelog:fragment {"id":"github-platform-bot-transactions.md","category":"fixed","type":"patch","issue":null,"pull_request":null,"author":"mentordosnerds"} -->
- Accept GitHub platform-signed bot transactions while preserving exact author identity, signature, receipt and file-scope checks; expose safe commit diagnostics when ownership proof fails. ([@mentordosnerds](https://github.com/mentordosnerds))
<!-- fast-forward-changelog:end-release -->
