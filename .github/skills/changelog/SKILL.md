---
name: changelog
description: Record independent Fast Forward Changelog fragments, check contributions, inspect release plans, maintain history, and publish an approved commit. Use this package's workflow and verified release facts.
license: MIT
compatibility: Requires an installed fast-forward/changelog CLI on PHP 8.5+, or an existing global changelog command. Git is needed for contribution comparison and releases; authorized GitHub access is needed only for GitHub history, policy checks and publication.
metadata:
  author: php-fast-forward
  source: original
  repository: https://github.com/php-fast-forward/changelog
---

# Fast Forward Changelog

Produce one independently reviewable change fragment for each coherent change,
then let the version workflow collect approved fragments into `CHANGELOG.md`.
Use this procedure when contributing changes, checking a PR, previewing a release,
backfilling history, formatting the central document, or publishing approved notes.
Ordinary contribution agents create fragments and check them; they never edit
`CHANGELOG.md` or apply a version transaction as part of a normal feature PR.
Do not manufacture release facts, infer impact solely from commit messages, or
manually maintain a second release history.

## Prerequisites and authority

Read applicable repository instructions and inspect existing Git status before
changing files. Use the consumer repository as the working directory. The package
defaults to `.changelog/`, `CHANGELOG.md`, English Keep a Changelog presentation,
stable tags prefixed `v`, and Git baseline `HEAD`.

Use `vendor/bin/changelog` after a project Composer installation; use `changelog`
when a global Composer installation is already available. Verify the installed
CLI with `vendor/bin/changelog list --raw` and the relevant command's `--help`.
The supported workflow commands are `add`, `check`, `status`, `version`, `notes`,
`backfill`, `format`, and `publish`. If the runtime is missing or older, report that
prerequisite and obtain existing installation authority before installing it.
Installing this skill copies instructions only and does not install the PHP CLI.

There is no initialization command or mandatory local configuration file.
`add` creates the fragment directory only when needed. Flags provide settings;
an optional custom PHP template is executable code and must be explicitly trusted.
Do not use a fragment request as authorization to commit, push, publish a tag,
create a GitHub Release, or alter user-wide configuration. Honor authority already
given in the current task without asking for the same permission again.

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

   `--issue=123`, `--pull-request=456`, and `--author=login` are optional. Create a
   fragment before its PR exists without placeholders. Login may have a leading
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
valid. Compare a PR to an explicit fetched baseline when contribution evidence
is required:

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

This writes the generated receipt journal and central snapshot, then removes
exactly the validated consumed fragments. Review `CHANGELOG.md`, the generated
`.changelog/release-plan.json` and those deletions together. The receipt records
transaction evidence; do not edit it as a second history or treat its hash as
approval. If interrupted, preserve journal/central/remaining fragments and retry
with the same settings. New or modified fragments block stale recovery; inspect
the diagnostic instead of deleting them or changing the receipt to force a retry.
Existing `Unreleased` sections are legacy content preserved during import; new
pending changes belong exclusively to independent `.changelog/` fragments.

## Historical notes and presentation

Backfill and format are explicit maintainer maintenance operations, normally
performed by the history workflow in its own reviewable PR. An ordinary change
request does not authorize those operations. Choose history evidence explicitly
when needed. `--source=tags` uses actual stable
Git tags without fetching GitHub notes. `--source=github --repository=owner/repo`
uses GitHub published notes for versions established by Git tags; `auto` uses
available configured sources. Missing historical notes get an explicit localized
message. Unknown dates remain unknown; neither commit time nor today's date is
invented as a historical publication date. Existing sections retain their notes.

```sh
vendor/bin/changelog backfill --dry-run --source=tags --no-interaction
vendor/bin/changelog backfill --source=tags --no-interaction
vendor/bin/changelog format --dry-run --locale=pt-BR --source=tags --no-interaction
vendor/bin/changelog format --locale=pt-BR --source=tags --no-interaction
vendor/bin/changelog notes 1.2.3 --no-interaction
```

Backfill and format preserve all pending fragments. Formatting changes recognized
structural headings and keeps prose, fenced code, notes, dates and references;
it does not translate descriptions. `notes` reads the exact maintained body,
without the outer version heading. `--output=release-notes.md` writes that body
only when an output file is requested. Without a version argument, notes selects
a receipt release version, then an actual stable Git baseline.

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

Then preview `vendor/bin/changelog format --template=changelog-template.php
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
to avoid accidentally selecting later commits. Publication verifies committed
receipt/central blobs, base ancestry, every base fragment and hash, the absence
of all pending Markdown at the approved commit, recalculated version/impact and
exact central section notes before creating missing remote objects.

Existing conflicting tags are never moved; divergent release notes are never
updated. A matching tag without its release or an uncertain create response is
recovered through verified remote reads. Repeat the same SHA to finish a partial
publication. Maintenance receipts create no tag or release. Built-in presentation
can target an older approved SHA; custom PHP additionally requires that exact
checked-out HEAD and unchanged tracked template bytes.

## Finish with evidence

Report created paths, validations and exact Git/PR/release identities actually
observed. Distinguish local implementation, committed/pushed changes, merged PR,
installed runtime/skill, and published release. Do not claim native agent loading
or release publication from a structural validator, file copy or dry run.
