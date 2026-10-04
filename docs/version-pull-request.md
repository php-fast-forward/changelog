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

A merged consolidation awaiting its public tag/release blocks another version
transaction. This service never publishes tags or releases.

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
while that version remains in the maintained history without its stable tag.

The service needs contents/PR write permissions and the policy's read permissions.
Unit tests replace Git, GitHub, planner and authority boundaries.
