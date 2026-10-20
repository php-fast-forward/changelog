# AGENTS - Fast Forward Changelog

This repository contains the standalone changelog domain, CLI and automation
used across Fast Forward PHP packages.

## Repository Surfaces

- CLI entrypoint: [`bin/changelog`](bin/changelog)
- Console wiring and invokable commands: [`src/Console/`](src/Console/)
- Native skill payload installation and integrity: [`src/Skill/`](src/Skill/)
- Explicit project setup, published workflow discovery and create-only caller writes: [`src/Installation/`](src/Installation/)
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
  use verified full version tags, as requested by the maintainer. Consumers select
  a published full version, reviewed immutable commit or verified release channel;
  repository-local reusable calls test the caller's exact revision. Merging requires review and
  passing checks for the current head. Installing elsewhere or publishing real
  tags/releases requires separate user authorization; retain authority already
  explicitly given for the current task.
- Edit `resources/skills/fast-forward-changelog` as the sole published skill
  source. Reserve `.agents/skills` for skills consumed by this project; do not
  generate host aliases or copies there. Validate resource and archive changes
  with `composer test:packaging`. Consumer installation and native host activation
  remain explicit operations, separate from package distribution. Keep the skill
  focused on the durable lifecycle: preserve an existing reviewed integration;
  discover and verify the selected published release for adoption or requested
  updates, then inspect its documentation and actual CLI help. Do not freeze the
  procedure to one release line or silently upgrade an ordinary contribution.
  This source reference needs no installed copy or host alias.

## This Repository's Changelog

- The first public release, `0.1.0`, is published. During initial `0.x` development,
  use `patch` for compatible fixes and explicit `minor` for new or breaking
  development changes, describing any affected contract. Reserve an explicit
  `major` note for the maintainer's deliberate `1.0.0` stability milestone.
  The CLI's generic impact calculation remains unchanged.
- Read [the canonical skill](resources/skills/fast-forward-changelog/SKILL.md)
  before recording notes, validating contributions or maintaining releases here.
  It is also the internal procedure; no copy or host-discovery adapter is needed.
- In this source checkout use `php bin/changelog` instead of the consumer's CPX
  invocation or installed binary. Inspect `php bin/changelog list --raw` and command help
  before choosing flags. Validate pending notes with `php bin/changelog check`
  and compare a contribution with `php bin/changelog check --since=origin/main`.
- Add one unique `.changelog/*.md` fragment per coherent observable change.
  Preserve inherited pending notes and the central history in ordinary PRs.
  Leave optional `author` absent unless an actual contributor identity is known;
  do not infer a personal credit from the repository's organization.
- `CHANGELOG.md` is consolidated version history. Initial development notes,
  including the former central `Unreleased` content, were curated into independent
  first-delivery fragments before publication. Never add new pending notes to the
  central file or recreate an `Unreleased` heading alongside fragments.
- Explicit editorial maintenance may replace inherited pending notes only under
  the verified human maintenance policy, with new validated committed summaries
  and exact Git blob/mode evidence. Preserve the original notes in Git and map
  them to the summaries for review. This does not authorize publication or edits
  to already published releases; ordinary contribution rules remain in force.
- Let the workflow prepare one managed version PR per configured release line;
  this repository currently uses `main` and `changelog/version`. A prepared version
  and green checks are not publication authority: review its exact head, history
  and fragment deletions before any separately authorized merge/release.
- Consumers maintaining parallel `1.x`/`2.x` lines MUST select the matching
  `base-branch` and a different `managed-branch` for each line. Backport selected
  fixes rather than merging a later major's complete history into an older line.
  Confirm the planned current and next major during review: branch names do not
  impose a SemVer range; only stable tags ancestral to the selected base establish
  its baseline. See [parallel maintenance](docs/automation-map.md#parallel-maintenance-lines).
- Keep concrete release tags immutable. Optional `v1`/`v2` channel refs identify
  already published commits; they are not maintenance branches and cannot replace
  concrete tags as version evidence. Configuring or moving channels is a separate
  operation with its own reviewed workflow and publication authority.
- This repository updates `v<major>` branches through
  `repository-release-channels.yml`, called explicitly after effective publication.
  These refs select published stable commits, never the current `main` tip.
  Manual recovery runs from `main` with the existing full tag and exact released
  SHA; verify the resulting ref. Preserve its old-SHA condition and fast-forward
  check, and never replace an unrelated branch or abbreviated tag.
- A channel update does not authorize another release. `@v0` can cross breaking
  development minors; keep the skill/CPX constraint aligned with its declared
  product contract rather than assuming it has the same range.

## Design Notes

- Keep runtime dependencies independent of `fast-forward/dev-tools`.
- Keep invokable commands thin; focused services own changelog behavior.
- Construct collaborators through factories/composition and inject every side-effect boundary.
- Use interface-to-class aliases and autowiring for ordinary service construction.
  Keep factories only for domain validation/transformation or replaceable operations
  with runtime inputs. Create pure values and standard exceptions directly; do not
  introduce wrapper factories merely to mock construction in tests.
- Preserve deterministic rendering and exact historical Markdown.
- Project setup is an explicit `install` operation. Preserve existing callers,
  their pins/settings and foreign installer ownership. Derive new defaults from
  the selected published workflow, bind application to the reviewed preview and
  create only absent files. Dry runs and JSON previews never install a skill or
  create directories. Native skill receipts retain their own version/integrity
  contract; do not manufacture NPX ownership or claim host activation.
- The native skill installer manages only this runtime's canonical payload through
  explicit install/check/update operations. Preserve local edits, foreign installer
  ownership and recovery evidence. Its receipt records local provenance, not trust
  or native host activation. Automatic warnings are local, read-only and limited to
  proven outdated copies on human terminal paths; never query a registry, update
  files or change machine/CI output as an incidental command effect.
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
- [Repository CI scope](.github/scripts/ci-impact.md): classifies complete immutable PR changes for internal tests and docs; preserves required contexts and falls back to full validation without changing contribution or publication policy.
- [Published changelog skill](resources/skills/fast-forward-changelog/SKILL.md): uniquely named portable consumer procedure; outputs validated fragments and approved lifecycle operations without granting publication authority.
- [Adoption guide](docs/adoption.md): introduces runtime and workflow adoption with explicit install and release boundaries.
- [Guided project installation](docs/project-installation.md): owns `install` questions, published workflow defaults, confirmation and separate component results; never replaces existing automation or third-party skill state.
- [Skill distribution guide](docs/skills.md): owns native install/check/update, independent installer ownership and discovery validation; distinguishes copies from native host activation.
- [Composer skill installation](docs/composer-skills.md): documents the optional donor/plugin route and the verified Fast Forward bundle alternative; consumer activation remains explicit.
- [Docker runtime guide](docs/docker.md): defines the self-contained CLI image and direct Action invocations; image registry publication remains separately authorized.
- [Automation map](docs/automation-map.md): maps public/internal automation, parallel maintenance selectors and publication-channel boundaries; defines consumption paths and shared-archive boundaries.
- [Release-channel operation](.github/update-release-channels.md): maintains published major branches with exact tag/commit evidence and conditional ref updates; never creates releases or moves concrete tags.
- [Consumer workflow example](resources/workflows/README.md): explains the single caller, reviewed product pin and optional lifecycle settings; publication starts disabled.
- [Container fixture](tests/Container/verify.md): validates the installed image with disposable Git repositories and synthetic credentials; performs no real publication.
