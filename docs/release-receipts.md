# Release transactions and local recovery

A release PR updates `CHANGELOG.md` and removes the consumed Markdown fragments.
It adds no `.changelog/release-plan.json`, manifest or second release history.
New history is ordinary readable Markdown, without generated JSON comments.

## Local application

The planner describes an immutable transaction in memory: the source commit,
current/next version, effective impact, consumed fragment hashes, exact notes and
original/resulting central bytes. A plan ID identifies those bytes; it grants no
publication authority. For Git-backed local operations, the machine summary's
`commit_message` contains the same scalar trailers used by version automation.
Commit only the reported `affected_files` with that exact message when an
approved local consolidation must later be published. Do not invent the hashes.

For application, the serialized plan is a local recovery journal outside the
versioned tree. Git-backed consumers use their Git administrative directory,
including a linked worktree's own administrative directory. Consumers without
Git use injected system-temporary storage scoped to their project and managed
paths. Successful operations remove that journal as well. The journal is never
an affected release file and never enters an automation-created Git tree.

Before writing, application checks the selected source commit, original central
bytes, complete committed fragment inventory and every consumed hash. A selected
custom PHP template must also match its approved regular base blob before it
executes. Unrelated staged and unstaged work is preserved.

The shared directory lock covers journal writing, central replacement, inventory
revalidation, exact fragment removal and journal cleanup. The journal is written
before the central file. It is deleted after all selected fragments have been
removed successfully. Maintenance does not consume fragments and also removes
its local journal on completion.

An interrupted operation retains the journal and any remaining fragments.
Retry the same settings to restore the saved exact version, date and notes
instead of calculating another release. New or modified inputs reject stale
recovery. Never edit the journal or delete newer inputs to force a retry.
A failed cleanup can be retried after the durable history and deletions are
already complete.

The journal contains canonical JSON evidence with exact original/resulting hashes
and recovery bytes. Its codec rejects unknown, duplicate or missing fields,
unsupported schemas, unsafe paths, malformed hashes and mismatched plan IDs.
These checks protect recovery consistency; they do not establish who approved
publication.

## Pending publication after successful application

Journal deletion does not erase pending-publication detection. Before preparing
a new version from nonempty fragments, the planner compares all maintained stable
sections with the latest reachable stable Git tag. A later untagged stable section
blocks the next transaction regardless of the new fragments' patch, minor or
major impact. The check also applies to preexisting history and works in a fresh
checkout; it never treats a Markdown heading as a published tag. Ordinary history
maintenance and an empty fragment inventory remain available.

For a correctly merged consolidation, retry its approved publication. If newer
fragments were retained by a stale merge, keep publication's complete-consumption
check. With no published release tag, recover through a reviewed revert of the
failed consolidation's central changes plus restoration of its consumed exact
source-base fragments, preserving later contributions. The next single version
PR can then consume the complete pending set. See [version PR recovery](version-pull-request.md#refresh-before-merge-and-recover-a-stale-consolidation).

## Git automation and publication

Version automation builds its tree from the fresh approved source base and
changes only the central document and consumed-fragment deletion entries.
The signed Bot commit records scalar trailers for its source base, plan ID,
resulting central hash and option fingerprint. There is no JSON file in that
tree. PR titles, bodies, branch names and copied trailers confer no authority.

Managed-PR policy independently verifies Bot identity, signature, ancestry,
output bytes and complete central/deletion scope. Publication recompiles the
release evidence from the source-base Git blobs and the approved central history.
It recalculates SemVer impact and notes and rejects incomplete consolidation.
See [version PRs](version-pull-request.md), [policy](policies.md) and
[publication](publication.md).

Older generated histories remain readable for migration. Their technical
comments are not emitted in new history; explicit formatting removes the legacy
generated presentation while preserving the parsed Markdown content.
