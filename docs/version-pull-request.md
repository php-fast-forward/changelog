# Managed version pull requests

`VersionPullRequestServiceInterface::synchronize(ReleaseOptions,
VersionPullRequestInput)` maintains one exclusive automation branch and PR.
The defaults are base `main`, managed branch `changelog/version` and creator
`github-actions[bot]`. Trusted composition supplies these values; no PR-head
configuration is executed.

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

Before calculating another version, the planner checks every maintained stable
section against the latest reachable stable Git tag. Any section above that
baseline blocks a new transaction, even when a new fragment requests a different
impact. A pending `1.0.1` cannot be skipped by a minor fragment that would otherwise
calculate `1.1.0` from tag `v1.0.0`. Section order and custom presentation do not
change the check. Prerelease sections and build metadata do not change stable
numeric precedence. Preexisting untagged stable history is subject to the same
rule; Markdown is not proof of publication.

The check survives fresh checkouts and successful journal cleanup. Completing the
approved stable tag unblocks the next transaction. History-only maintenance and
an empty fragment inventory do not invent another version. This service never
publishes tags or releases.

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
Uncertain ref/PR responses are confirmed through fresh reads. Unconfirmed
outcomes return a conflict with known safe identifiers.

## Result

The immutable result exposes status, PR number/URL, head SHA, plan ID, version,
maintenance flag and diagnostics. Statuses distinguish created, updated,
unchanged, dry-run, none, refused and conflict. With no new fragments, a completed
consolidation returns none. New fragments cannot prepare the same version again
while any later stable section remains in the maintained history without a
reachable stable tag.

The service needs contents/PR write permissions and the policy's read permissions.
Unit tests replace Git, GitHub, planner and authority boundaries.

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
