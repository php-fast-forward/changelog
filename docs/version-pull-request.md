# Managed version pull requests

`VersionPullRequestServiceInterface::synchronize(ReleaseOptions,
VersionPullRequestInput)` maintains one exclusive automation branch and PR.
Trusted composition supplies the base branch (`main`), managed branch
(`changelog/version`), Bot actor (`github-actions[bot]`), title and `dryRun`.
These values are automation inputs, not configuration executed from a PR head.
The input factory rejects unsafe Git refs, equal base/managed branches, non-Bot
logins and oversized or multiline titles.

## Fresh-base planning

The caller must check out the newest approved base before calling this service.
The service resolves the remote base ref and requires local HEAD to match it.
It calls the same `ReleasePlannerInterface::plan($options, 'version')` used by
consumer code, then checks the plan's original central/receipt bytes and every
consumed fragment hash against committed base data. Dirty local snapshots and
stale base IDs are refused. It never checks out or plans from the existing
managed branch, so rebasing/updating a version PR cannot increment a previously
prepared version again.

A fully applied, merged receipt awaiting its public tag/release returns
`pending-publication`: central/receipt bytes must match and all consumed
fragments must already be absent. A partially applied transaction is refused
for explicit recovery. This service does not publish tags or releases.
Maintenance uses a null next version and is explicitly identified in the result
and PR body.

## Ownership and idempotency

All open PRs on the exclusive managed branch are inventoried. Multiple PRs,
other base lines, fork heads, inconsistent branch/PR SHAs or unsigned/manual
head changes fail closed. Existing PRs require the shared
`PullRequestPolicyInterface::inspect()` authorization with
`kind: managed-version`, central permission and the exact head SHA. An orphan
branch with generated contents requires the same shared `inspectHead()` proof.
An orphan branch still exactly at the base commit is safe initial recovery.

Ownership uses actual GitHub Bot account IDs, verified signatures, the exact
receipt/footer and immutable head contents. The receipt's saved base must be an
ancestor of both its head and the current base. Comparison scope may contain
only generated central/receipt changes and removals of fragments whose saved
base hashes match. Unknown files, renames, incomplete removals, malformed data
and comparison scopes of 300 or more files are refused. Editable PR body/title
or self-declared labels do not establish ownership. See
[the common PR policy](policies.md) and the
[GitHub comparison API](https://docs.github.com/en/rest/commits/commits#compare-two-commits).

If an existing owned PR already contains the same plan ID, the service returns
`unchanged` without creating another commit. If that same generated branch is
orphaned after an uncertain PR-creation response, it creates only the missing
PR and keeps the existing commit. No changes produce `none`; an existing owned
PR is preserved with a diagnostic for explicit review/closure. An identical
new/base tree also creates no empty commit or PR. `dryRun` performs zero writes,
including during orphan recovery.

## Git transaction

The generated tree uses the **fresh base tree**, then changes only central
changelog bytes, receipt bytes and deletion entries for the approved consumed
fragments. Unrelated files come from that fresh base rather than a stale managed
head. Central/receipt entries are ordinary `100644` blobs; deletions use null
blob IDs. This follows GitHub's
[tree API contract](https://docs.github.com/en/rest/git/trees#create-a-tree).

A new commit has the old managed head (when present) and the fresh base as
parents, deduplicated. Keeping the old head as a parent allows a fast-forward
ref update while the new tree reflects the fresh base. The commit includes
`Changelog-Plan: <plan-id>`. Author, committer and signature overrides are omitted
so the authenticated GitHub App/Bot can receive platform signature verification.
The generated commit is independently checked with the common signed-head
policy before any branch is updated. See
[GitHub commit creation](https://docs.github.com/en/rest/git/commits#create-a-commit)
and [Bot signing requirements](https://docs.github.com/en/authentication/managing-commit-signature-verification/about-commit-signature-verification#signature-verification-for-bots).

GitHub may use its `web-flow` account as the committer of a signed Bot-authored
transaction. That route requires the exact platform account and an independently
pinned GraphQL signature proving GitHub's signing key, in addition to the Bot
author, valid REST signature, receipt and complete file scope. See
[GitHub signature evidence](https://docs.github.com/en/graphql/reference/git#gitsignature).
If generated-head ownership fails, controlled diagnostics report only the
complete generated SHA and allowlisted creation-verification metadata; they do
not expose credentials, raw signature payloads or arbitrary API response text.

Both remote branch tips and local HEAD are rechecked before tree generation
and again before ref publication. Existing refs update with `force: false`;
initial refs are created without replacing an existing branch. A concurrent
new descendant commit cannot be discarded by this fast-forward update. REST
refs do not accept an expected-old-SHA CAS parameter, so managed branches must
remain exclusive to this automation and authorized maintainers must avoid
concurrent forced resets. Any observed change is reported for replanning. See
[the ref API](https://docs.github.com/en/rest/git/refs#update-a-reference).

A lost ref-write response is confirmed only by a fresh ref GET matching the
intended commit. A lost PR-create response is confirmed by the exclusive branch
query; a lost PR-edit response is confirmed by a PR GET at the intended head.
Unconfirmed tree/commit writes can leave unreachable Git objects, and an
uncertain ref/PR write may already have succeeded. Such outcomes return
`conflict`, retaining known plan/PR/head information and requiring inspection
before retrying. No rollback or force push rewrites another actor's history.

## Result contract

`VersionPullRequestResult` exposes `status`, optional `prNumber`, `url`,
`headSha`, `planId`, `version`, plus `maintenance` and safe `diagnostics`.

| Status | Meaning |
| --- | --- |
| `created` | The missing owned PR was created or confirmed. |
| `updated` | The existing owned PR was updated or confirmed. |
| `unchanged` | The same plan already exists on its owned PR. |
| `dry-run` | The plan and ownership were checked without writes. |
| `none` | No transaction changes remain; no empty PR was created. |
| `pending-publication` | A merged release plan awaits separate publication. |
| `refused` | Inputs/ownership/base proof failed before mutation. |
| `conflict` | A write or concurrent-state outcome needs inspection. |

Runtime composition needs contents write and PR write permissions on the
configured repository, along with the common policy's read permissions. Unit
tests replace Git, GitHub, planner, receipts, ownership and factory boundaries;
they create no live GitHub objects and execute no PR-head code.
