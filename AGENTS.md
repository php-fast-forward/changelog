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
- Process construction boundary: [`src/Process/`](src/Process/); HTTP client and secure entropy construction belong to the service provider.
- Trusted automation and Docker actions: [`src/Automation/`](src/Automation/), [`.github/actions/`](.github/actions/)
- GitHub orchestration: [`.github/workflows/`](.github/workflows/); `changelog*` are public reusables and `repository-*` belong to this package.
- Packaged CLI image: [`Dockerfile`](Dockerfile), [runtime guide](docs/docker.md)
- Tests: [`tests/`](tests/)
- Docs: [`docs/`](docs/)
- Published history: [`CHANGELOG.md`](CHANGELOG.md)

## Setup And Local Workflow

- Install dependencies with `composer install` when authorized for this checkout.
- Run focused tests with `vendor/bin/phpunit`; validate metadata with `composer validate --strict`.
- PHP `^8.5` is the minimum supported runtime; code updates MUST NOT require PHP 8.6.
- Run `composer check` for independent quality gates. Rector/ECS check and fix
  scripts are separate; revalidate after applying fixes.
- Run `composer test:workflows` after reusable workflow changes; its isolated
  Bash/JavaScript fixtures need Node.js only for development. Run actionlint on
  changed workflow contracts and verify the effective consumer jobs separately.
- Keep `composer.lock` for reproducible contributor, CI and Docker CLI builds.
  Library consumers resolve the constraints in `composer.json` independently.
- Keep unit tests under the namespace hierarchy of the source they exercise.
  Unit tests MUST replace filesystem, Git, processes, network, clock and host
  state with injected doubles. Integration and packaging fixtures are separate
  suites and MUST use disposable directories and synthetic credentials.
- Require 100% executable source-line coverage without excluding production
  adapters or factories. Coverage is executed-path evidence, not correctness.
- Public and private methods MUST explain contracts in PHPDoc, including
  relevant invariants, observable effects and failure conditions.
- Preserve unrelated work and stage only reviewed task files. External Actions
  use verified full version tags, as requested by the maintainer. References to
  this package from consumers use reviewed immutable commits until release;
  repository-local reusable calls test the caller's exact revision. Merging requires review and
  passing checks for the current head. Installing elsewhere or publishing real
  tags/releases requires separate user authorization; retain authority already
  explicitly given for the current task.
- Edit `resources/skills/fast-forward-changelog` as the sole published skill
  source. Reserve `.agents/skills` for skills consumed by this project; do not
  generate host aliases or copies there. Validate resource and archive changes
  with `composer test:packaging`. Consumer installation and native host activation
  remain explicit operations, separate from package distribution. Keep the skill's
  `^1.0` consumer contract, references and executable examples aligned when changing
  consumer integration; this source reference needs no installed copy or host alias.

## This Repository's Changelog

- Read [the canonical skill](resources/skills/fast-forward-changelog/SKILL.md)
  before recording notes, validating contributions or maintaining releases here.
  It is also the internal procedure; no copy or host-discovery adapter is needed.
- In this source checkout use `php bin/changelog` in place of the consumer's
  `vendor/bin/changelog`. Inspect `php bin/changelog list --raw` and command help
  before choosing flags. Validate pending notes with `php bin/changelog check`
  and compare a contribution with `php bin/changelog check --since=origin/main`.
- Add one unique `.changelog/*.md` fragment per coherent observable change.
  Preserve inherited pending notes and the central history in ordinary PRs.
  Leave optional `author` absent unless an actual contributor identity is known;
  do not infer a personal credit from the repository's organization.
- `CHANGELOG.md` is consolidated version history. Its initial `Unreleased` is
  legacy migration input: the version transaction promotes it into the concrete
  release and removes the pending heading. Never add new notes there or recreate
  it alongside fragments.
- Explicit editorial maintenance may replace inherited pending notes only under
  the verified human maintenance policy, with new validated committed summaries
  and exact Git blob/mode evidence. Preserve the original notes in Git and map
  them to the summaries for review. This does not authorize publication or edits
  to already published releases; ordinary contribution rules remain in force.
- Let the workflow prepare the single `changelog/version` PR. A prepared version
  and green checks are not publication authority: review its exact head, history
  and fragment deletions before any separately authorized merge/release.

## Design Notes

- Keep runtime dependencies independent of `fast-forward/dev-tools`.
- Keep invokable commands thin; focused services own changelog behavior.
- Construct collaborators through factories/composition and inject every side-effect boundary.
- Use interface-to-class aliases and autowiring for ordinary service construction.
  Keep factories only for domain validation/transformation or replaceable operations
  with runtime inputs. Create pure values and standard exceptions directly; do not
  introduce wrapper factories merely to mock construction in tests.
- Preserve deterministic rendering and exact historical Markdown.
- Ordinary contributions add unique fragments. Approved consolidation consumes
  only its validated set and updates the single central history through services.
- Release PRs update the central history and delete consumed fragments; they
  MUST NOT introduce technical plan files or generated metadata comments.
- Recovery journals stay outside the versioned tree and are removed on success.
  Privileged automation verifies its trusted context and committed Git evidence.

One root contract owns source, tests, docs and development. Ordinary directories
do not need duplicate `AGENTS.md` files. Host entrypoints are adapters; skill
procedures stay in their package entrypoint. The instruction map is recorded in
[`docs/instruction-map.md`](docs/instruction-map.md).

## Child DOX Index

- [Source namespaces](docs/source-layout.md): domain and transport ownership, canonical PHP imports and the major namespace/factory migration; ordinary folders share the root contract.
- [Integration dependencies](docs/integration-dependencies.md): records verified Git/GitHub candidate versions and adoption criteria without claiming SDK installation or behavioral equivalence.
- [Development scripts](scripts/README.md): maps the four used PHP quality helpers; production CLI and images do not invoke them.
- [Published changelog skill](resources/skills/fast-forward-changelog/SKILL.md): uniquely named portable consumer procedure; outputs validated fragments and approved lifecycle operations without granting publication authority.
- [Adoption guide](docs/adoption.md): introduces runtime and workflow adoption with explicit install and release boundaries.
- [Skill distribution guide](docs/skills.md): records discovery/copy validation and distinguishes those checks from native host activation.
- [Composer skill installation](docs/composer-skills.md): documents the optional donor/plugin route and the verified Fast Forward bundle alternative; consumer activation remains explicit.
- [Docker runtime guide](docs/docker.md): defines the self-contained CLI image and direct Action invocations; image registry publication remains separately authorized.
- [Automation map](docs/automation-map.md): maps stable public reusables/Actions, repository-prefixed internal workflows and helpers; defines consumption paths and shared-archive boundaries.
- [Consumer workflow example](resources/workflows/README.md): explains the single caller, reviewed product pin and optional lifecycle settings; publication starts disabled.
- [Container fixture](tests/Container/verify.md): validates the installed image with disposable Git repositories and synthetic credentials; performs no real publication.
