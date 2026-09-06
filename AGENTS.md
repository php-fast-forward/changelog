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

## Design Notes

- Keep the package free of runtime dependencies on `fast-forward/dev-tools`.
- Keep command orchestration thin and push changelog behavior into focused services.
- Preserve deterministic Keep a Changelog rendering so consumer workflows can rely on stable output.
