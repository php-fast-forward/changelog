# AGENTS - Fast Forward Changelog

This repository contains the standalone changelog domain, CLI and automation
used across Fast Forward PHP packages.

## Repository Surfaces

- CLI entrypoint: [`bin/changelog`](bin/changelog)
- Console wiring and invokable commands: [`src/Console/`](src/Console/)
- Independent fragments and effective impact: [`src/Changeset/`](src/Changeset/), [`src/Fragment/`](src/Fragment/), [`src/Version/`](src/Version/)
- Contribution validation: [`src/Validation/`](src/Validation/)
- Historical Markdown and presentation: [`src/History/`](src/History/), [`src/Template/`](src/Template/)
- Exact release transactions: [`src/Release/`](src/Release/)
- Approved-commit publication: [`src/Publication/`](src/Publication/)
- Filesystem, Git and GitHub boundaries: [`src/Filesystem/`](src/Filesystem/), [`src/Git/`](src/Git/), [`src/GitHub/`](src/GitHub/)
- Trusted automation and composite actions: [`src/Automation/`](src/Automation/), [`actions/`](actions/)
- Tests: [`tests/`](tests/)
- Docs: [`docs/`](docs/)
- Published history: [`CHANGELOG.md`](CHANGELOG.md)

## Setup And Local Workflow

- Install dependencies with `composer install` when authorized for this checkout.
- Run focused tests with `vendor/bin/phpunit`; validate metadata with `composer validate --strict`.
- PHP `^8.5` is the minimum supported runtime; code updates MUST NOT require PHP 8.6.
- Run `composer check` for independent quality gates. Rector/ECS check and fix
  scripts are separate; revalidate after applying fixes.
- Keep unit tests under the namespace hierarchy of the source they exercise.
  Unit tests MUST replace filesystem, Git, processes, network, clock and host
  state with injected doubles. Integration and packaging fixtures are separate
  suites and MUST use disposable directories and synthetic credentials.
- Require 100% executable source-line coverage without excluding production
  adapters or factories. Coverage is executed-path evidence, not correctness.
- Public and private methods MUST explain contracts in PHPDoc, including
  relevant invariants, observable effects and failure conditions.
- Preserve unrelated work and stage only reviewed task files. Workflow
  dependencies use verified immutable commits. Merging requires review and
  passing checks for the current head. Installing elsewhere or publishing real
  tags/releases requires separate user authorization; retain authority already
  explicitly given for the current task.
- Edit `.agents/skills/changelog` as the sole skill source. Run
  `node scripts/skills-sync.mjs --write` after changes and `--check` before review.
  Claude/Copilot copies are generated real files, not independent procedures.

## Design Notes

- Keep runtime dependencies independent of `fast-forward/dev-tools`.
- Keep invokable commands thin; focused services own changelog behavior.
- Construct collaborators through factories/composition and inject every side-effect boundary.
- Preserve deterministic rendering and exact historical Markdown.
- Ordinary contributions add unique fragments. Approved consolidation consumes
  only its validated set and updates the single central history through services.
- Receipts are generated transaction evidence, not editable release history or
  publication authority. Privileged automation verifies its own trusted context.

One root contract owns source, tests, docs and development. Ordinary directories
do not need duplicate `AGENTS.md` files. Host entrypoints are adapters; skill
procedures stay in their package entrypoint. The instruction map is recorded in
[`docs/instruction-map.md`](docs/instruction-map.md).

## Child DOX Index

- [Changelog skill](.agents/skills/changelog/SKILL.md): portable consumer procedure; outputs validated fragments and approved lifecycle operations without granting publication authority.
- [Adoption guide](docs/adoption.md): introduces runtime and workflow adoption with explicit install and release boundaries.
- [Skill distribution guide](docs/skills.md): records discovery/copy validation and distinguishes those checks from native host activation.
- [Skill copy verifier](scripts/skills-sync.md): documents no-write drift checking and bounded regeneration of the two declared adapters.
