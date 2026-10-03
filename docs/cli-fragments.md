# Authoring and checking fragments

Create a change record without initialization or a local configuration file:

```sh
vendor/bin/changelog add "Updates the dependency while preserving the public API." --category=changed --type=patch --no-interaction
vendor/bin/changelog check --no-interaction
```

A global Composer installation uses the same commands through `changelog`.
The caller's working directory supplies the consumer project, rather than the
CLI's installation directory. `add` creates only `.changelog/` when necessary
and one independent Markdown fragment. It never creates configuration files.

The category defaults to `changed`. Omitting `--type` uses the category default,
and the resulting fragment always persists the effective type. Supplying
`--type=patch` with `--category=changed` is supported. `added`, `changed` and
`deprecated` default to minor; removed defaults to major; fixed/security to patch.

Optional `--issue`, `--pull-request` and `--author` metadata can be omitted.
Creating a fragment before its pull request exists is supported. Authors may
be supplied with or without a leading `@`; the parser writes the canonical
login without that prefix. The body retains meaningful Markdown whitespace.
See [the complete fragment schema](specification/fragment-format.md).

## Names, collisions and scoped commits

Default names contain an injected random identifier, such as
`change-0123456789abcdef0123456789abcdef.md`. Existing generated names trigger
up to five attempts with newly generated identities. Exhaustion fails without
replacing another fragment.

An explicit `--name` is a complete filename ending in `.md`, for example:

```sh
vendor/bin/changelog add "Changes the internal adapter." --category=changed --type=patch --name=internal-adapter.md --no-interaction
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
vendor/bin/changelog add "Fixes parser handling." --category=fixed --commit --commit-message="chore: document parser fix" --no-interaction
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
vendor/bin/changelog check --since=origin/main --no-interaction
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
argument, it selects the pending receipt's next version, then the highest stable
tag reachable from the current `HEAD`; an unrelated branch's tag cannot change
the default. An absent section produces a diagnostic.

```sh
vendor/bin/changelog notes 1.2.3 --no-interaction
vendor/bin/changelog notes 1.2.3 --output=notes/release-1.2.3.md --no-interaction
```

Without `--output`, stdout receives the exact body bytes. The optional output is
a new canonical project-relative file. Existing files, the consolidated
changelog and every path within the fragment directory are refused; export
cannot replace a maintained input. See [history framing](history.md).

## PHP service contracts

`FragmentWriterInterface::add()` accepts validated `ReleaseOptions`, message,
category, optional type/name/issue/pullRequest/author, opt-in commit and optional
commit message; it returns the absolute created path. Its parser and renderer
are the schema authority, and filesystem/Git/identifier generation are injected
boundaries. Business services do not construct their collaborators.

`CheckServiceInterface::check($options, $since, $centralChangeAuthorized,
$waiverAuthorized)` returns `ValidationReport`. The optional authorization
booleans belong to verified automation context. `changesets` contains the valid
pending inventory, `errors` contains independently keyed diagnostics and
`isValid()` is true only when every selected check passes. A report's pending
inventory count must never be interpreted as its new-PR-contribution count.

`ChangesetValidatorInterface::validate()` and `validatePaths()` also accept
`requireFragment=false` for inventory checking; all discovered fragments remain
subject to path and schema validation. This mode does not invent a waiver.

`IdentifierGeneratorFactoryInterface::create()` uses a secure engine by default.
Tests provide a `Random\Engine` double through the factory; service construction
requests no random bytes, and unit tests need no filesystem, Git process, network,
HOME configuration or real entropy reads.
