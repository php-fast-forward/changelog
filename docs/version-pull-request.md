# Managed version pull requests

Use this guide to review or refresh the PR that prepares a release. The workflow
collects merged fragments into central history; it does not publish or merge that
PR itself. Defaults are base `main`, branch `changelog/version` and Bot
`github-actions[bot]`.

1. Review the planned version, notes and consumed fragment deletions.
2. If the base advances, run preparation again and review the refreshed head.
3. Merge only after required checks and review pass. The lifecycle defaults to
   `publish: true`: it verifies that approved merge, creates its exact version
   tag and publishes a GitHub Release. An explicit `publish: false` skips both.
   See [publication](publication.md).

## Fresh source base

The caller checks out the newest approved base. Local HEAD and the remote base
must match before planning and again before publishing the ref. The planner
validates the original central bytes, complete committed fragment inventory and
consumed hashes at that source commit. It never plans from an existing version
branch, so updating a PR cannot bump its prepared version again.

The generated tree starts from the fresh base tree. It updates only
`CHANGELOG.md` and removes the validated consumed fragments. It adds no plan
file to `.changelog/` or elsewhere. Maintenance updates only the central file.
Local recovery journals are outside the versioned tree.

The planner takes the greatest stable version from committed source
`CHANGELOG.md` sections and stable Git tags ancestral to that source base.
Section order does not determine the baseline; prereleases and pending headings
do not establish a stable version. With neither source, the initial baseline is
`0.0.0`. The complete committed fragment inventory then establishes the next
version's effective impact.

For example, tag `v1.7.0`, committed history through `1.7.1` and a new patch
fragment produce a `1.7.2` PR. The planner preserves the earlier history bytes;
it does not reuse `1.7.1`, require its tag or publish it retroactively. Its new
comparison link starts at the reachable `v1.7.0` tag, and no link is invented
for the untagged `1.7.1` section. This also
works when history exists without any stable tag. History-only maintenance and
an empty fragment inventory do not invent another version. Preparation itself
never publishes tags or releases.

An explicit `publish: false` therefore does not block later version PRs. It
means that merging the prepared PR changes central history without creating
either the version tag or GitHub Release. Enabling publication later applies to
the next approved version merge; it does not publish older consolidated versions
automatically. Committed history establishes version progression, not proof that
an earlier version was published.

## Ownership and repeatability

The service inventories PRs on the exclusive branch. Multiple PRs, other base
lines, forks, inconsistent SHAs and unsigned/manual heads fail closed. An
existing PR or orphan generated branch must pass the common signed-head policy.
An orphan branch exactly at the source base can be recovered without replacing
another actor's changes.

The generated commit records `Changelog-Base`, `Changelog-Plan`,
`Changelog-Output` and `Changelog-Options` scalar trailers. Policy verifies the
configured Bot's immutable identity, signature, source-base ancestry, option
fingerprint, central output hash and complete source fragment deletions.
Trailers, PR bodies, labels and branch names do not prove identity.
See [the policy contract](policies.md).

An unchanged plan produces no additional commit. An uncertain PR creation is
recovered by querying the exclusive branch. Empty plans create no empty commit
or PR. Dry runs make no GitHub writes.

## Title customization

The lifecycle's `version-pr-title` and the individual workflow/Action's `title`
accept `{current_version}` and `{next_version}`. A `1.2.0` → `1.3.0` plan can
render `chore(release): 1.2.0 → 1.3.0`; the first baseline is `0.0.0`.
Maintenance has no next release, so both values are the current version.
Only these two placeholders expand. Templates allow at most 1024 bytes;
literal/resolved titles must be a single line of at most 256 bytes.
A title-only update preserves the signed head, body and plan; `dry-run` previews it.
The internal CLI entrypoint accepts `github version --title=<template>`.

## Git transaction

Central entries are regular `100644` blobs; fragment deletions use null blob IDs.
Unrelated files come from the fresh source base. An update commit retains the old
managed head and fresh base as parents, deduplicated, so a normal fast-forward
ref update preserves the prior transaction while refreshing its tree.

GitHub creates the commit without custom author/committer/signature overrides.
The service verifies the generated signed Bot commit before moving the branch.
The verified `web-flow` platform-committer route additionally requires pinned
GraphQL proof that GitHub signed that same SHA.

Remote branch tips and local HEAD are rechecked around writes. Ref updates use
`force: false`; no rollback or force push discards another actor's history.
Uncertain ref/PR responses are confirmed through fresh reads. PR confirmation
requires the intended head, title and any requested generated body; a title-only
refresh preserves existing human prose. Unconfirmed outcomes return a conflict
with known safe identifiers, without repeating the write.

## Result

The immutable result exposes status, PR number/URL, head SHA, plan ID, version,
maintenance flag and diagnostics. Statuses distinguish created, updated,
unchanged, dry-run, none, refused and conflict. With no new fragments, a completed
consolidation returns none. New fragments advance from the greatest committed
history or ancestral-tag baseline, including when the previous consolidation was
not published.

The PHP entrypoint is `VersionPullRequestServiceInterface::synchronize(ReleaseOptions,
VersionPullRequestInput)`. It needs contents/PR write permissions and the
policy's read permissions; trusted composition supplies its settings.

## Refresh before merge and recover a stale consolidation

An open managed version PR is authorized only when its generated source base is
exactly the current PR base. If another contribution lands, refresh the same
managed branch from the newest base before merging. The updater can still verify
ownership of the older signed head and replace its generated tree through a
non-forced descendant commit. Older-head ownership is not approval to merge its
stale consolidation. Consumers must make this check required and require the
branch to be up to date; see [the policy contract](policies.md).

If a stale consolidation was already merged and newer fragments remain,
publication refuses the incomplete result. Keep those newer fragments. Provided
no release tag was published, make a reviewed recovery change that reverts the
failed consolidation's central-history changes and restores its consumed
fragments from the exact source-base blobs. Preserve later contributions and
unrelated approved work. Then refresh one version PR from that recovered base;
it consumes the complete combined pending set and recalculates its single
release impact. Do not delete newer fragments, bypass publication checks, move
a tag or create a competing version branch to force the old plan through.

---

[Documentation index](index.md)
