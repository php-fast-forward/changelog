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
