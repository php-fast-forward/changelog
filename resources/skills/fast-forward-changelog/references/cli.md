# Local CLI and approved maintenance

Load for local authoring, contribution diagnostics, read-only plans, history
maintenance or separately authorized publication. Supports
`fast-forward/changelog ^1.0` (minimum `1.0.0`) on PHP `^8.5` with Composer and
Git. Inspect `composer show fast-forward/changelog`, then
`vendor/bin/changelog list --raw` and the relevant `help <command>`.
Use `changelog` instead when an existing global Composer install is selected.
A missing or older runtime needs a separately authorized installation or a
compatible procedure; do not silently change application dependencies.

The defaults are `.changelog/`, `CHANGELOG.md`, English Keep a Changelog,
`v`-prefixed stable tags and baseline `HEAD`. There is no `init` or mandatory
configuration file. `--cwd=/absolute/project` selects the working directory;
`--fragment-directory`, `--changelog-file`, `--base-ref`, `--locale`,
`--tag-prefix`, `--repository` and `--source` supply explicit settings.
Inspect command help for supported combinations. Credentials come from the
application's approved secret mechanism, never from notes or copied skill files.
The executable accepts `FF_CHANGELOG_TOKEN` (including an explicitly empty value), falling back to
`GITHUB_TOKEN` only when the first variable is absent.

When no repository setting is explicit, a canonical GitHub `origin` supplies
`owner/name`. This inferred identity keeps `auto` history on local tags without
GitHub requests. Explicit `--repository` retains `auto` source behavior;
`--source=tags` and `--source=github` select their source directly.
`github` contains automation operations; inspect `help github` rather than
constructing trusted PR context from user-controlled arguments.

## Write a contribution

1. Read the actual change and describe its observable effect in concise Markdown.
   Preserve significant spaces, code blocks and links. Do not invent issue/PR
   numbers, author identity, release dates, security claims or outcomes.
2. Select a category and effective SemVer impact. An explicit type may be lower
   than the category default when the reviewed change justifies that choice.

   | Category | Default type | Meaning |
   | --- | --- | --- |
   | `added` | `minor` | New functionality |
   | `changed` | `minor` | Existing behavior changed |
   | `deprecated` | `minor` | Behavior scheduled for removal |
   | `removed` | `major` | Behavior removed |
   | `fixed` | `patch` | Defect corrected |
   | `security` | `patch` | Verified security correction |

   `--type` accepts `major`, `minor`, or `patch`; the strongest effective type
   across all pending fragments determines the next release. A dependency update
   preserving the public API can use `changed` with explicit `patch`.
3. Run `add` with a message argument for unattended work:

   ```sh
   vendor/bin/changelog add "Preserves meaningful Markdown spaces when parsing fragments." --category=fixed --no-interaction
   vendor/bin/changelog add "Updates a dependency while preserving the public API." --category=changed --type=patch --no-interaction
   vendor/bin/changelog check --no-interaction
   ```

   `--issue`, `--pull-request`, and `--author` are optional. Supply
   `--pull-request=<number>` only when this contribution's origin is already known. Verify
   the repository, head branch and complete head SHA through the application's
   existing GitHub tooling, read-only API or browser; copy the positive number
   from that actual PR's canonical URL or metadata. Do not derive it from an
   issue, branch name or commit message. With `PULL_REQUEST` set to that verified
   number:

   ```sh
   vendor/bin/changelog add "Fixes Markdown parsing." --category=fixed --pull-request="$PULL_REQUEST" --no-interaction
   ```

   When the number is unknown, author without the field. Contribution checks do
   not require it, and opening a PR does not require a follow-up fragment edit
   or commit. Login may have a leading
   `@`; serialization stores the canonical login without it.
4. Inspect the exact created path and content. Generated names are collision
   resistant and never overwrite another fragment. An optional explicit
   `--name=markdown-spaces.md` must be a lowercase dash-separated slug plus `.md`,
   with no directory, hidden prefix or traversal. An explicit collision fails.

The generated fragment has restricted scalar frontmatter and the original body:

```markdown
---
category: changed
type: patch
---

Updates a dependency while preserving the public API.
```

Every generated fragment persists `category` and effective `type`. Optional
`issue`/`pull_request` are positive integers and `author` is a GitHub login.
Unknown metadata fails. Legacy `version` is a read compatibility alias migrated
to `type` on serialization; conflicting `version` and `type` fail. Do not write
legacy metadata in new fragments. Only direct Markdown fragments are accepted;
nested, hidden and symbolic candidates fail validation. Direct
`.changelog/AGENTS.md` is reserved and is never a contribution.

## Commit only on explicit intent

Without commit intent, leave the fragment as a reviewed local file. With existing
authorization to commit it, use the scoped option:

```sh
vendor/bin/changelog add "Fixes the documented parser behavior." --category=fixed --commit --commit-message="chore: document parser fix" --no-interaction
```

The CLI commits only the generated fragment and never pushes. Verify the created
path and commit afterwards. Do not stage every pending fragment or run `git add .`:
other contributors' fragments and unrelated staged/unstaged work are independent.
If the commit fails after creation, retain the reported path, inspect the index,
repair the specific Git condition and commit that file explicitly. Do not recreate
the entry or discard unrelated work to recover.

## Check a pull-request contribution

Local `check` validates the full pending inventory; an empty local inventory is
valid. `--since` reads only the committed baseline-to-`HEAD` diff, so untracked or
uncommitted notes cannot satisfy it. After the contribution is committed under
existing task authority, compare the PR to an explicit fetched baseline:

```sh
vendor/bin/changelog check --since=origin/main --no-interaction
```

An inherited pending fragment does not satisfy a new contribution. A valid added
fragment, new copy destination, or rename of a non-fragment into the fragment
directory can qualify. Modification, deletion, type change or rename of an
inherited fragment fails an ordinary PR. Ordinary PRs must not edit or rename the
central changelog. Fix every independent diagnostic and rerun the same check.

Trusted automation may inspect `--pull-request=<number>` together with `--since`
and `--repository=owner/repo` at the exact inspected PR head. Maintainer waivers
require verified issuer authority; editable labels or PR text alone do not grant
it. There is no user-controlled skip flag. A waiver can excuse a missing new
fragment and cannot suppress malformed metadata or unauthorized modifications.

A verified maintainer maintenance grant can remove an incorrect optional
`author` line from an inherited fragment. It cannot replace that value or alter
any other byte, path or regular-file mode; validation must match the committed
head. This is a narrowly scoped correction, separate from ordinary contributions
and waivers. Do not invent a contributor identity to fill the removed value.

## Preview and consolidate a release

An ordinary contributor may inspect the read-only plan with `status`. The version
workflow owns consolidation and opens its own reviewable version PR. Run the
following version commands only when explicitly tasked with maintaining that
workflow or performing a maintainer-authorized release transaction:

```sh
vendor/bin/changelog status --json --source=tags --no-interaction
vendor/bin/changelog version --dry-run --source=tags --no-interaction
vendor/bin/changelog version --check --source=tags --no-interaction
```

`status` prints the machine plan without writes. `--dry-run` describes changes
without writes. `--check` returns exit 1 when managed files would change and exit
0 when already current. Normal success is exit 0; invalid arguments are exit 2
and operational failures are exit 1. `version --check` therefore uses exit 1 for
both drift and failure; inspect its machine output or diagnostic to distinguish.

Review current/next version, impact, consumed paths, exact notes and affected
files. Stable tag build metadata may remain in the baseline; the next resolved
version is numeric. With existing consolidation authority, run the same settings
without `--dry-run`/`--check`:

```sh
vendor/bin/changelog version --source=tags --no-interaction
```

For a Git-backed release, the central document, complete pending fragment set and
accepted byte hashes must match the selected base before any journal write.
Keep intended release inputs committed at that base. Unrelated dirty files and
staged work remain allowed; use read-only status and fragment previews while
preparing an uncommitted contribution. A selected custom PHP template must also
match its regular base blob before execution and application.

This writes a recovery journal outside the versioned tree, updates the central
history and removes exactly the validated fragments. It deletes the journal
when application finishes. Review only `CHANGELOG.md` and the consumed-fragment
deletions in the version PR: no release-plan file is added to `.changelog/`.
New history is readable Markdown without generated metadata comments. If
interrupted, preserve the local journal and remaining fragments and retry the
same settings. Changed inputs block stale recovery; inspect the diagnostic
rather than editing the journal or deleting newer inputs to force a retry.
The machine summary includes `commit_message` with the same scalar trailers
used by version automation. If explicitly tasked with committing a local
consolidation for later publication, commit only its `affected_files` with that
exact message; never invent the hashes or stage unrelated work.
Existing `Unreleased` sections are legacy pending content: version consolidation
moves their descriptions into the new concrete version and removes the pending
heading. Matching categories are combined without rewriting description bytes.
Backfill and format retain pending content; new contributions belong exclusively
to independent `.changelog/` fragments.

When a concrete version is prepared with a known GitHub `owner/name`, missing
footer definitions are completed for all maintained SemVer versions. The oldest
version points to `releases/tag/<tag>`; each later version points to
`compare/<previous-tag>...<tag>` by semantic precedence. The configured prefix is
included and each complete tag is URL-encoded. Existing definitions and retained
`v`/`V` heading labels remain unchanged. No destinations are invented without a
known repository; `backfill`, `format` and no-op plans do not add references or
create an `Unreleased` link. These URLs do not establish publication authority.

## Historical notes and presentation

Backfill and format are explicit maintainer maintenance operations. The history
workflow previews them or applies them only on its disposable runner; it does
not commit, push or create a maintenance PR. To retain reviewed changes, apply
them in an authorized local checkout and submit the central-history diff through
the application's normal PR process. An ordinary change request does not
authorize those operations. Choose history evidence explicitly
when needed. `--source=tags` uses actual stable
Git tags without fetching GitHub notes. `--source=github --repository=owner/repo`
uses GitHub published notes for versions established by Git tags. With an explicit
repository, `auto` uses available configured sources; an inferred repository keeps
`auto` on local tags. Missing historical notes get an explicit localized
message. Unknown dates remain unknown; neither commit time nor today's date is
invented as a historical publication date. Existing sections retain their notes.

```sh
vendor/bin/changelog backfill --dry-run --source=tags --no-interaction
vendor/bin/changelog backfill --source=tags --no-interaction
vendor/bin/changelog format --dry-run --locale=pt-BR --source=tags --no-interaction
vendor/bin/changelog format --locale=pt-BR --source=tags --no-interaction
vendor/bin/changelog notes 1.2.3 --no-interaction
```

Backfill and format preserve all pending fragments. Maintenance PRs commit only
the central changelog. Recovery journals remain outside the versioned tree and
are removed on success.
Formatting changes recognized
structural headings and keeps prose, fenced code, notes, dates and references;
it does not translate descriptions. `notes` reads the exact maintained body,
without the outer version heading. `--output=release-notes.md` writes that body
only when an output file is requested. Without a version argument, notes selects
the highest stable SemVer maintained in the document, independently of section
order or dates. Unreleased and prerelease sections do not become the default;
explicit versions may still select prereleases. Build metadata breaks equal-core
version ties lexically. An actual reachable stable Git baseline is used only
when no stable maintained section exists.

Defaults need no file. For explicitly requested presentation customization,
create a trusted project `changelog-template.php` containing:

```php
<?php
return [
    'release_heading' => '## Release {version}',
    'release_heading_dated' => '## Release {version} on {date}',
    'category_headings' => ['fixed' => '### Corrections'],
];
```

In Git, the selected template must already be a reviewed regular file in the
selected base and match its exact working bytes before execution. Outside Git,
an explicitly trusted local PHP template remains supported. Then preview
`vendor/bin/changelog format --template=changelog-template.php
--dry-run --no-interaction`. Category keys remain the six canonical identifiers;
release headings require level two and `{version}`, dated headings also `{date}`,
and category headings require level three. Unknown settings fail. Custom PHP
executes code: use an approved tracked template in privileged workflows.

## Publish the approved consolidation

Publication requires existing authority and the exact approved merge commit,
not a moving branch or an editable PR-body assertion. Keep GitHub credentials in
the caller's normal authorized secret mechanism, never in fragments or this skill.
Preview with the full approved SHA:

```sh
vendor/bin/changelog publish --repository=owner/repo --target-sha="$APPROVED_SHA" --dry-run --no-interaction
```

Set `APPROVED_SHA` to the full approved merge SHA from the task's verified evidence.
With publication authority for that exact result, repeat without `--dry-run`.
The CLI resolves
`--target-sha` (default checked-out HEAD); supply the approved full SHA explicitly
to avoid accidentally selecting later commits. Publication reconstructs the
consolidation from scalar commit trailers, source-base fragment blobs and the
approved central history. It checks ancestry, option/output fingerprints,
complete fragment removal, recalculated version/impact and exact central notes.
Historical imports use the approved committed sections during publication;
previous GitHub notes are not fetched again to reconstruct the approved history.
A merge must resolve consistent transaction evidence; a squash must retain the
trailers. Missing or ambiguous evidence fails. No tracked plan file is required.
This is the generic CLI contract. The managed-PR reusable workflow additionally
binds the exact signed PR head to its approved merge through GitHub evidence and
identical complete Git trees. It can use that head's trailers after an ordinary
squash, including after branch deletion; it still tags the approved merge and
recomputes the full release against that merge's committed bytes. It never
infers this context from a branch name or PR-body assertion.

Existing conflicting tags are never moved; divergent release notes are never
updated. A matching tag without its release or an uncertain create response is
recovered through verified remote reads. Repeat the same SHA to finish a partial
publication. Maintenance consolidations create no tag or release. Built-in presentation
can target an older approved SHA; custom PHP additionally requires that exact
checked-out HEAD and unchanged tracked template bytes.
