# Authoring and checking fragments

Use PHP `^8.5` and CPX as described in [local setup](adoption.md).
Run from the consumer project, without initialization or a local configuration file:

```sh
cpx --skip-local fast-forward/changelog add \
  "Updates the dependency while preserving the public API." \
  --category=changed --type=patch --no-interaction
cpx --skip-local fast-forward/changelog check --no-interaction
```

`--skip-local` belongs to CPX and precedes the package. The remaining arguments
belong to Changelog. Existing Composer installs use the same arguments through
`vendor/bin/changelog`, or `changelog` on the global binary path. The working
directory selects the consumer project; `--cwd` can select it explicitly.
`add` creates `.changelog/` when necessary and one independent Markdown fragment.
It does not edit central history or create configuration files.

When the message is absent, interactive `add` asks for it and retries blank
answers while preserving Markdown spaces. With `--no-interaction`, supply the
message explicitly; invalid input fails before creating a fragment.

The category defaults to `changed`. Omitting `--type` uses the category default,
and the resulting fragment always persists the effective type. Supplying
`--type=patch` with `--category=changed` is supported. `added`, `changed` and
`deprecated` default to minor; removed defaults to major; fixed/security to patch.

Optional `--issue`, `--pull-request` and `--author` metadata can be omitted.
Creating a fragment before its pull request exists is supported. Authors may
be supplied with or without a leading `@`; the parser writes the canonical
login without that prefix. The body retains meaningful Markdown whitespace.
See [the complete fragment schema](specification/fragment-format.md).

Optionally supply `--pull-request=<number>` when this contribution's origin is
already known. Read
the actual PR with existing GitHub tooling, API or browser, verify its repository,
head branch and complete head SHA, then copy its positive number from the
canonical PR URL or trusted metadata. With `PULL_REQUEST` set to that number:

```sh
cpx --skip-local fast-forward/changelog add \
  "Fixes Markdown parsing." --category=fixed \
  --pull-request="$PULL_REQUEST" --no-interaction
```

Unknown metadata stays absent. Contribution checks do not require a PR number,
and opening the PR does not require a follow-up edit or commit to its fragment.

## Names, collisions and scoped commits

Default names contain an injected random identifier, such as
`change-0123456789abcdef0123456789abcdef.md`. Existing generated names trigger
up to five attempts with newly generated identities. Exhaustion fails without
replacing another fragment.

An explicit `--name` is a complete filename ending in `.md`, for example:

```sh
cpx --skip-local fast-forward/changelog add \
  "Changes the internal adapter." --category=changed --type=patch \
  --name=internal-adapter.md --no-interaction
```

Only lowercase letters/digits separated by single dashes are permitted before
the extension. Explicit collisions fail immediately. A name cannot contain
subdirectories, `..`, hidden-file prefixes or the reserved `AGENTS.md` name.

`--commit` is opt-in. Creation, parsing and canonical rendering complete first;
Git then receives only the generated repository-relative fragment path. The
default commit message is `chore: record changelog fragment`; `--commit-message`
customizes it when committing. Unrelated staged or unstaged files remain outside
this operation. No push is performed by fragment authoring.

```sh
cpx --skip-local fast-forward/changelog add \
  "Fixes parser handling." --category=fixed --commit \
  --commit-message="chore: document parser fix" --no-interaction
```

A write failure reports the exact candidate path and underlying error. If the
fragment exists but its commit fails, the error explicitly reports that the
created fragment is preserved. Inspect that path and Git's index, repair the
reported Git condition and commit the fragment explicitly; do not delete or
recreate unrelated staged changes to recover.

## Checking local state and PR contributions

`check` validates all pending fragment paths and schemas, preserving independent
diagnostics. A local check may have an empty fragment directory and performs no
Git comparison unless a baseline is supplied.

```sh
cpx --skip-local fast-forward/changelog check --since=origin/main --no-interaction
```

With a baseline, existing fragments inherited from the base do not satisfy the
contribution gate. The diff must add a valid new fragment. Added files and new
copy destinations qualify. Renaming a non-fragment into the fragment scope also
qualifies. Renaming, modifying, deleting or changing the type of an inherited
fragment fails an ordinary PR check. Hidden and nested Markdown candidates,
including symbolic links, are validated and cannot hide behind a waiver.
Root-level `.changelog/AGENTS.md` is reserved and never counts as a contribution.

Ordinary PRs may not edit or rename the consolidated `CHANGELOG.md`. Verified
version/backfill/format operations may change it, and a verified version
operation may delete its consumed fragments without adding a new one. Such
operations must still pass validation of the remaining inventory.

Verified human editorial maintenance may replace pending notes only with new
committed, validated summaries and exact Git blob/mode evidence. This does not
grant publication authority or allow rewriting published history. See
[validation policies](policies.md).

A maintainer waiver can excuse the absence of a new fragment. The automation
must independently verify the issuer's authority. A label's editable text or a
PR body's assertion is insufficient. If authority is unavailable, pass no waiver
and fail the contribution gate. A waiver never suppresses schema errors,
inherited-fragment mutation or unauthorized consolidated-document edits. There
is no public CLI skip/waiver flag that can manufacture this trusted context.

## Reading and exporting exact notes

`notes 1.2.3` returns the maintained release body without the outer release
heading, structural delimiters or document reference footer. Category headings,
descriptions, inline links and code fences remain in that body. With no version
argument, it selects the highest stable SemVer maintained in the central history,
regardless of section order or release dates. Unreleased and prereleases are
excluded from this default; an explicit version can still select a prerelease.
Build metadata breaks equal-core-version ties lexically. Only when there is no
stable maintained section does it fall back to a stable tag reachable from
`HEAD`; an unrelated branch's tag cannot change the default. An absent selected
section produces a diagnostic.

```sh
cpx --skip-local fast-forward/changelog notes 1.2.3 --no-interaction
cpx --skip-local fast-forward/changelog notes 1.2.3 \
  --output=notes/release-1.2.3.md --no-interaction
```

Without `--output`, stdout receives the exact body bytes. The optional output is
a new canonical project-relative file. Existing files, the consolidated
changelog and every path within the fragment directory are refused; export
cannot replace a maintained input. See [history framing](history.md).

## Public CLI

In the table, append the arguments to `cpx --skip-local fast-forward/changelog`.
Use `help <command>` for command-specific help.

| Command | Example arguments | Behavior |
| --- | --- | --- |
| `add` | `add "Description" --category=fixed` | Create one validated fragment; commit is opt-in. |
| `check` | `check --since=origin/main` | Validate the inventory and committed contribution delta. |
| `status` | `status --json --source=tags` | Print a plan without managed writes. |
| `version` | `version --dry-run --source=tags` | Preview consolidation; applying requires an authorized release task. |
| `notes` | `notes 1.2.3` | Return the exact maintained body; see export rules above. |
| `backfill` | `backfill --dry-run --source=tags` | Preview missing historical versions. |
| `format` | `format --dry-run --locale=pt-BR` | Preview presentation changes while preserving descriptions. |
| `publish` | `publish --target-sha=<approved-full-sha> --repository=owner/repo --dry-run` | Validate committed evidence and inspect remote state before publication. |

The `github` entrypoint belongs to the Actions' machine-operation contract;
see [Docker and automation](docker.md). Ordinary local tasks use the commands above.

### Shared settings

| Flag | Default / contract |
| --- | --- |
| `--cwd`, `-C` | Consumer working directory, default `.`. |
| `--fragment-directory` | Project-relative `.changelog`. |
| `--changelog-file` | Project-relative `CHANGELOG.md`. |
| `--locale`, `-l` | `en` or `pt-BR`, default `en`. |
| `--template`, `-T` | `keep-a-changelog` or an explicitly trusted project PHP file; Git-bound files must match the selected base before execution. |
| `--base-ref`, `-b` | Git planning baseline, default `HEAD`. |
| `--tag-prefix` | Stable tag prefix, default `v`. |
| `--repository`, `-r` | Explicit GitHub `owner/name`, otherwise inferred from a canonical GitHub `origin`; remote operations require an effective identity. |
| `--source`, `-s` | History source: `auto`, `github` or `tags`; inferred repository identity keeps `auto` local. |
| `--resolve-pull-requests[=true\|false]` | Missing PR discovery, default `false` locally. GitHub lifecycle jobs default to `true`. Independent of historical source selection. |

Without an explicit repository, canonical GitHub `origin` supplies `owner/name`
and `auto` uses local tags. Explicit `--repository` retains `auto`'s GitHub-source
behavior; explicit `--source=tags` or `--source=github` takes precedence.
Tag-based historical import does not suppress GitHub reads for enabled PR
discovery. See [reference and history behavior](history.md).

### Command options and exit codes

| Command | Additional options and short forms |
| --- | --- |
| `add` | `--category/-c`, `--type/-t`, `--name/-f`, `--issue/-i`, `--pull-request/-p`, `--author/-a`, `--commit`, `--commit-message/-m`. |
| `check` | `--since/-S`, `--pull-request/-p` for verified authorization at the exact checkout. |
| `status` | `--json/-j`. |
| `version`, `backfill`, `format` | `--dry-run/-d`, `--check/-c`; these modes are mutually exclusive. |
| `notes` | Optional version argument and `--output/-o` for a new export file. |
| `publish` | `--target-sha/-t` (default resolved checkout HEAD), `--dry-run/-d`; supply the approved complete SHA explicitly in automation. |
| `github` | `--since/-S`, `--pull-request/-p`, `--target-sha/-t`; machine settings keep explicit long names. |

`-s` selects historical source; `-S` selects a contribution baseline. Uppercase
`-T` leaves `-t` available to each command. Direct Changelog execution retains
Symfony's `-h`, `-q`, `-v`, `-V` and `-n` global options. Through CPX 2.1, use
`help <command>` instead of `--help`; `--version` identifies CPX itself.
A commit message does not enable `--commit`.

Successful commands exit `0`, invalid arguments exit `2`, and validation or
operational failures exit `1`. Mutation `--check` also exits `1` when files
would change; inspect the diagnostic to distinguish drift from failure.
`status --json` prints the consolidation plan on stdout, including its exact
`affected_files` and `commit_message`. A plan ID does not authorize a write.

An ordinary contribution does not apply a release. Read
[release transactions and recovery](release-receipts.md) before authorized local
application, [history](history.md) before maintenance, and
[publication](publication.md) before creating a tag or release.

## PHP service contracts

`FragmentWriterInterface::add()` accepts validated `ReleaseOptions`, message,
category, optional type/name/issue/pullRequest/author, opt-in commit and optional
commit message; it returns the absolute created path. Its parser and renderer
are the schema authority, and filesystem/Git/identifier generation are injected
boundaries. Business services do not construct their collaborators.

`CheckServiceInterface::check($options, $since, $centralChangeAuthorized,
$waiverAuthorized, $authorizationKind)` returns `ValidationReport`. The optional
authorization booleans and kind belong to verified automation context; the kind
defaults to `ordinary`. Central authorization alone does not authorize fragment
deletions: release consumption requires the verified managed-version kind;
editorial replacement requires verified human maintenance and its committed
summary/blob/mode proof.
`changesets` contains the valid
pending inventory, `errors` contains independently keyed diagnostics and
`isValid()` is true only when every selected check passes. A report's pending
inventory count must never be interpreted as its new-PR-contribution count.

`ChangesetValidatorInterface::validate()` and `validatePaths()` also accept
`requireFragment=false` for inventory checking; all discovered fragments remain
subject to path and schema validation. This mode does not invent a waiver.

`IdentifierGeneratorInterface` resolves to `RandomIdentifierGenerator` with an
injected `Random\Randomizer`. The provider supplies a secure engine; tests can
inject a randomizer backed by a deterministic `Random\Engine` double. Service
construction requests no random bytes, and unit tests need no filesystem, Git
process, network, HOME configuration or real entropy reads.

---

[Documentation index](index.md)
