# Adopt Fast Forward Changelog

Install the runtime, record independent fragments in ordinary PRs, then let an
approved version PR consolidate the single `CHANGELOG.md`. Published GitHub notes
come from that approved document at its exact merge commit.

## Install and verify the runtime

The runtime requires PHP 8.5 or later and Composer. In an authorized consumer
checkout, install a release or reviewed source revision containing the eight
commands documented here:

```sh
composer require --dev fast-forward/changelog
vendor/bin/changelog list --raw
vendor/bin/changelog add --help
```

An existing global Composer installation exposes `changelog` instead. Commands
operate on the consumer working directory, including when the package itself is
installed elsewhere; `--cwd=/path/to/project` selects it explicitly. A skill
installation is separate and does not install PHP, Composer or this runtime.
See [skill distribution and evidence](skills.md).

There is no `init` step or mandatory local configuration. Defaults are
`.changelog/`, `CHANGELOG.md`, English Keep a Changelog presentation, tag prefix
`v`, history source `auto` and baseline `HEAD`. `add` creates the fragment directory
only when needed. Public flags provide settings; an optional explicitly selected
trusted PHP template changes presentation.

## First ordinary contribution

Run a complete unattended authoring/check route:

```sh
vendor/bin/changelog add "Corrects Markdown parsing while preserving meaningful spaces." --category=fixed --no-interaction
vendor/bin/changelog check --no-interaction
vendor/bin/changelog check --since=origin/main --no-interaction
```

Fetch/select the intended baseline before the last command. Review the exact
created fragment. PR, issue and author metadata are optional, so author before a
PR exists without placeholders. A generated fragment always persists its category
and effective type. For a dependency change preserving the public API, use
`--category=changed --type=patch`. Category defaults and restricted frontmatter
are documented in [fragment format](specification/fragment-format.md).

New names never overwrite existing fragments. Explicit `--name=parser-spaces.md`
must be a direct lowercase dash slug with `.md`. Meaningful Markdown body spaces
are preserved. Hidden, nested and symbolic candidates fail; direct
`.changelog/AGENTS.md` is reserved. Existing pending fragments inherited from the
baseline do not count as this PR's contribution. Ordinary PRs add a new valid
fragment and do not alter the consolidated document or inherited fragments.
The version workflow owns writing `CHANGELOG.md` and opens a separate version PR;
ordinary contribution agents do not run `version` to apply their own changes.
An existing `Unreleased` section is preserved legacy content. New pending changes
are represented only by independent `.changelog/` fragments.

Commit only with explicit intent. `add --commit` commits solely its generated
fragment; `--commit-message` sets the message. It does not push. Otherwise review
and stage that exact path yourself. Never stage every pending fragment or use
`git add .` to record a single contribution. If creation succeeds but commit
fails, preserve the reported path and index, repair the Git condition and commit
that same fragment instead of creating a duplicate.

## Public CLI

| Command | Example invocation | Observable behavior |
| --- | --- | --- |
| `add` | `add "Description" --category=fixed` | Creates one validated unique fragment; commit is opt-in |
| `check` | `check --since=origin/main` | Validates inventory and the contribution delta |
| `status` | `status --json --source=tags` | Prints a release plan without managed writes |
| `version` | `version --dry-run --source=tags` | Previews consolidation; omit dry-run to apply an authorized transaction |
| `notes` | `notes 1.2.3` | Returns exact central body bytes; optional `--output=notes.md` creates a new output file without replacing existing or maintained files |
| `backfill` | `backfill --dry-run --source=tags` | Previews adding only missing historical versions |
| `format` | `format --dry-run --locale=pt-BR` | Previews presentation changes while preserving descriptions/history |
| `publish` | `publish --target-sha=<approved-full-sha> --repository=owner/repo --dry-run` | Proves committed evidence and reads remote state before creating missing objects |

`--no-interaction` disables prompts. A message argument is required for unattended
`add`. All commands accept the shared settings below; their use does not imply
every setting affects every operation.

| Flag | Default / contract |
| --- | --- |
| `--cwd` | Consumer working directory, default `.` |
| `--fragment-directory` | Project-relative `.changelog` |
| `--changelog-file` | Project-relative `CHANGELOG.md` |
| `--locale` | `en` or `pt-BR`, default `en` |
| `--template` | `keep-a-changelog` or an explicitly trusted project PHP file |
| `--base-ref` | Git planning baseline, default `HEAD` |
| `--tag-prefix` | Stable tag prefix, default `v` |
| `--repository` | Optional GitHub `owner/name`; required for GitHub-specific operations |
| `--source` | `auto`, `github`, or `tags` |

`add` also accepts `--type`, `--name`, `--issue`, `--pull-request`, `--author`,
`--commit` and `--commit-message`. `check` accepts `--since` and an optional
`--pull-request` for independently verified PR authorization at that exact checkout.
There is no editable-label or CLI skip escape. `publish` accepts `--target-sha`
(default resolved checkout HEAD) and `--dry-run`; always supply the exact approved
SHA in automation. `version`, `backfill` and `format` accept `--dry-run` and
`--check`. Normal success exits 0, invalid arguments exit 2, and operational
failures exit 1. Mutation `--check` also exits 1 when files would change: distinguish
drift from failure using its output/diagnostic. `status --json` prints the same
machine plan used by consolidation; stdout is suitable for automation.

## Release and history workflow

The normal route is contribution PR → version workflow → reviewed version PR →
publication at the approved merge SHA. Applying `version`, `backfill` or `format`
locally requires an explicit maintainer release/maintenance task; it is not part
of an ordinary contribution. These commands also power their dedicated workflows.

Preview `status`, then `version --dry-run` with the same settings. Review the
machine plan's current/next version, effective impact, consumed paths, historical
additions, notes and affected files. Its receipt ID proves consistency and does
not provide approval.

An authorized version operation writes its generated receipt journal, writes the
planned central snapshot, then removes only the validated consumed fragments.
Review the central file, receipt and those deletions together in the version PR.
Retry the same settings after an interruption; preserve the journal and remaining
fragments. New or modified inputs fail closed. See [transaction and recovery
contracts](release-receipts.md).

Backfill and format preserve pending fragments. History uses actual stable Git
tags as version authority; GitHub can supply published notes/dates for those tags.
Annotated tag dates have explicit provenance, lightweight tags with unavailable
publication dates remain undated, and missing notes receive a localized message.
Existing notes, prose, code fences and references are retained. `--source=tags`
avoids GitHub historical-note requests. `--locale=pt-BR` translates recognized
structural headings and package messages, leaving descriptions untouched. See
[history/template contracts and a complete custom PHP example](history.md).

Publication takes the exact approved merge SHA, validates base/fragment/SemVer/
central evidence, and uses only its exact central section body. It refuses any
remaining pending Markdown at that commit, including newer fragments that were
not consumed by the plan. Tags pointing elsewhere and divergent published notes
are never changed. A partial tag without release can be completed by retrying the
same SHA. Maintenance receipts create no tag/release. Custom PHP presentation
also requires the approved checkout and unchanged tracked template bytes.
See [publication contracts](publication.md).

## Consumer automation surfaces

Reuse the shipped entrypoints at a reviewed immutable repository commit:

| Composite action | Reusable workflow | Responsibility |
| --- | --- | --- |
| `actions/check` | `.github/workflows/changelog-check.yml` | Contribution and trusted-policy check |
| `actions/dependabot` | `.github/workflows/changelog-dependabot.yml` | Author one dependency-update fragment |
| `actions/version` | `.github/workflows/changelog-version.yml` | Prepare a reviewable version PR |
| `actions/publish` | `.github/workflows/changelog-publish.yml` | Publish an approved consolidation SHA |
| `actions/history` | `.github/workflows/changelog-history.yml` | Prepare historical backfill/format maintenance |

Read the selected action/workflow's inputs and permission contract before wiring
it. Keep ordinary PR checks unprivileged; use trusted base code for operations
holding write credentials. Independently verify maintainer waiver issuer and
version-PR provenance; a label name, bot-looking author or PR body is insufficient.
Select credentials that permit the repository's required checks on generated PRs,
and keep their secrets in the consumer's normal approved mechanism. The version
PR must pass review/current-head checks before its merge SHA reaches publication.
Package checkout/installation must target a reviewed immutable revision; adopting
this guide does not claim any workflow has been deployed in a consumer repository.
For monorepos, run GitHub automation from the repository root and configure
repository-relative package paths, such as `packages/example/.changelog` and
`packages/example/CHANGELOG.md`. Local CLI calls may instead use the package's
nested working directory. Do not pass nested local cwd semantics to the GitHub
automation interfaces: their repository API paths are rooted at the Git tree.

## PHP API

CLI and automation share injected services. Obtain the standalone composition
through the package's container service provider, or inject these interfaces into
the consumer's own PSR-11 container. Constructors perform no filesystem, Git,
network, clock or credential observation. Factories construct values/collaborators.

| Interface | Contract |
| --- | --- |
| `ReleaseOptionsFactoryInterface` | `create(array $values = []): ReleaseOptions`; defaults then explicit values, unknown settings rejected |
| `FragmentWriterInterface` | `add($options, $message, $category='changed', $type=null, $name=null, $issue=null, $pullRequest=null, $author=null, $commit=false, $commitMessage='chore: record changelog fragment'): string`; absolute created path |
| `CheckServiceInterface` | `check($options, $since=null, $centralChangeAuthorized=false, $waiverAuthorized=false): ValidationReport`; authorization belongs to verified automation context |
| `ReleasePlannerInterface` | `plan($options, $operation='version'): ReleasePlan`; version/backfill/format exact evidence without mutation |
| `ReleaseApplierInterface` | `apply($plan): bool` and read-only `isApplied($plan): bool`; exact journal/snapshot/inventory transaction |
| `HistoryCodecInterface` | `parse($markdown)`, `render($document, $template, $preservePresentation=false)`, `notes($document, $version)`; pure Markdown/history boundary |
| `HistoryImporterInterface` | `import($document, $options, $template, $tags)` and `currentVersion($tags, $tagPrefix='v')`; actual stable tag evidence |
| `PublicationServiceInterface` | `publish($options, string $approvedSha, bool $dryRun=false): PublicationResult`; complete SHA required at service boundary |

`ReleaseOptions` carries workingDirectory, fragmentDirectory, changelogFile,
locale, template, baseRef, tagPrefix, repository and source. `ReleasePlan::summary()`
exposes id/mode/base_sha/current_version/next_version/impact/consumed/
historical_versions/notes/affected_files/resuming. `ValidationReport::isValid()`
checks its diagnostic map; valid pending inventory is not a new-contribution
count. Exact accepted input hashes are transaction evidence. Publication results
expose state/version/tag/sha/url/actions with `summary()`. See the linked domain
documents for construction boundaries, receipt schema and failure recovery.

## A short adoption prompt

Paste this into an authorized agent session in the consumer repository:

```text
Adote Fast Forward Changelog neste projeto usando uma revisão imutável revisada.
Instale o CLI local via Composer e a skill changelog em escopo de projeto por cópia.
Use fragmentos independentes; valide contribuições contra a base do PR e preserve trabalho alheio.
Configure os workflows reutilizáveis com mínimos privilégios e PR de versão revisável.
Não edite CHANGELOG.md manualmente nem publique tags/releases sem autorização explícita para o SHA aprovado.
Mostre os arquivos criados, verificações executadas e limites de instalação/ativação observados.
```
