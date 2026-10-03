# AGENTS - Fast Forward Changelog

This repository contains the standalone changelog domain and CLI runtime used
across Fast Forward PHP packages.

## Repository Surfaces

- CLI entrypoint: [`bin/changelog`](bin/changelog)
- Console wiring: [`src/Console/`](src/Console/)
- Commands: [`src/Console/Command/`](src/Console/Command/)
- Domain model: [`src/Document/`](src/Document/), [`src/Entry/`](src/Entry/)
- Parsing and rendering: [`src/Parser/`](src/Parser/), [`src/Renderer/`](src/Renderer/)
- File and Git helpers: [`src/Filesystem/`](src/Filesystem/), [`src/Git/`](src/Git/)
- Changelog services: [`src/Manager/`](src/Manager/)
- Tests: [`tests/`](tests/)
- Docs: [`docs/`](docs/)
- Release history: [`CHANGELOG.md`](CHANGELOG.md)

## Setup And Local Workflow

- Install dependencies with `composer install`.
- Run focused tests with `vendor/bin/phpunit`.
- Validate package metadata with `composer validate --strict`.
- PHP `^8.5` is the minimum supported runtime; code updates MUST NOT require PHP 8.6.
- Run `composer check` for the package's independent quality gates. Rector and
  ECS have separate check and fix scripts; revalidate after applying fixes.
- Keep unit tests under the namespace hierarchy of the source they exercise.
  Unit tests MUST replace filesystem, Git, processes, network, clock and host
  state with injected doubles. Integration and packaging fixtures are separate
  suites and MUST use disposable directories and synthetic credentials.
- Require 100% executable source-line coverage without excluding production
  adapters or factories. Coverage is evidence of executed paths, not correctness.
- Public and private methods MUST explain their contracts in PHPDoc, including
  relevant invariants, observable effects and failure conditions.
- Preserve unrelated work and stage only reviewed task files. Pin workflow
  dependencies to verified immutable commits. A merge requires review and
  passing checks for the current head; installing the package elsewhere or
  publishing real tags/releases requires separate user authorization.

## Design Notes

- Keep the package free of runtime dependencies on `fast-forward/dev-tools`.
- Keep command orchestration thin and push changelog behavior into focused services.
- Preserve deterministic Keep a Changelog rendering so consumer workflows can rely on stable output.

The instruction map is recorded in [`docs/instruction-map.md`](docs/instruction-map.md).
The root contract currently owns the common source, tests and development
workflow; ordinary folders do not need duplicate child contracts.
