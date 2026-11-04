# Dependabot PATCH evaluation and coordinated rollout

`.github/workflows/dependabot-patch.yml` is an opt-in, read-only reusable workflow.
Its default is `opt-in: false`, `mode: evaluate`. It returns candidate evidence,
**never merge authority**. `mode: execute` fails before API inspection. No token
secret, approval, merge, auto-merge mutation, consumer installation or repository
setting change is implemented. This is a usable evaluation stage; execution is
blocked pending a separately reviewed shared executor contract.

## Proof and limits

The live PR must match the event's exact HEAD and base, remain open/non-draft,
and belong to the consumer repository on both sides. The author must match all
three official Dependabot attributes: `dependabot[bot]`, numeric ID `49699333`,
and `Bot`. The evaluator reads JSON at immutable SHAs through the SDK and never
checks out or executes PR code, Composer scripts, plugins, actions or templates.
Only the called workflow's immutable runtime is checked out, without persisted
credentials. Only `pull_request` events are accepted, including on reruns.

The caller chooses `ecosystem: npm` (default) or `composer`, plus a single safe
`directory` (default `.`). Only the selected manifest/lock pair may change. npm
supports `package-lock.json` v3, matching root manifest requirements and direct
root `node_modules` entries, including scoped packages. Every changed package
must have stable numeric versions, a matching registry.npmjs.org package tarball,
SHA-512 integrity and unchanged non-version metadata. Requirements, install-script
flags, links, workspaces, graph changes, nested/transitive updates, aliases and
nonstandard registries block. Exact manifest versions and corresponding lock
PATCH changes are supported, as are conservative caret/tilde lock-only updates.
Integrity proves the locked tarball identity, not code safety. The SHA-512 digest
is not fetched/recomputed by this evaluator; trusted security review must inspect
that artifact.

Composer supports modified `composer.lock` and optionally `composer.json`.
Both files must be regular non-executable Git blobs. Complete bounded JSON,
package membership, order, names, sections and unknown metadata are validated.
Every changed locked dependency must move forward within the same stable major
and minor. Changes to scripts, dependency requirements, platform settings,
repositories, plugins or other metadata block. Source origin remains unchanged;
distribution URLs may change only their immutable reference. Both references must
agree. This is provenance evidence, not proof that upstream code is safe.

Direct `require`/`require-dev` packages support exact, caret or tilde full stable
versions conservatively within the constraint's major/minor floor. A changed
manifest constraint must itself be a matching PATCH and correspond exactly to
the locked version transition. A direct lock-only PATCH with a supported unchanged
range is accepted. Unchanged complex ranges, transitive updates, graph changes,
new/removed dependencies and packages moving sections block for manual review.
Groups are accepted only when **every** member independently passes. A mixed
patch/minor group blocks. Pre-release/build versions, mutable action tags, Actions
SHA bumps, other lock formats and unrecognized files block. Titles and
Dependabot commit metadata are never PATCH evidence.

At least two distinct check names with expected GitHub App IDs must be configured
for independent code review and security/deployment viability inspection. Each
must occur exactly once, succeed, complete and match the exact HEAD. Skipped,
neutral, missing, pending, spoofed and ambiguous results block. The PR identity is
read again after inspection to reject a concurrent HEAD/base change. Check names
alone do not prove security or review quality: their trusted producers must
inspect the exact HEAD **and base**, upstream code, advisories, deployment
compatibility and downstream effects. This evaluator cannot attest that semantic
contract; its report explicitly leaves those gates unresolved.

## Components considered

The workflow reuses official `actions/checkout@v7.0.1`,
`actions/github-script@v8.0.0` and its paginated GitHub SDK. Tags were verified
against the official Git repositories during implementation. No custom HTTP,
credential provisioning or new runtime dependency is needed.

[dependabot/fetch-metadata](https://github.com/dependabot/fetch-metadata) supplies
useful per-dependency metadata, but it does not independently establish the
complete manifest/lock delta or security review. Adding it would duplicate this
narrow proof without replacing it. It is therefore not a trust gate here.

[Native auto-merge](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/automatically-merging-a-pull-request)
requires the repository's
[Allow auto-merge setting](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/configuring-pull-request-merges/managing-auto-merge-for-pull-requests-in-your-repository).
Coordination records `php-fast-forward/changelog` as `allow_auto_merge=false`;
this task does not change it. Native auto-merge is not armed: it can execute after
an earlier external inspection or lease expires. Future native execution would
need all external gates enforced continuously as required GitHub checks/rules,
with a coordinated single executor and approved setting change.

A future [direct REST merge](https://docs.github.com/en/rest/pulls/pulls#merge-a-pull-request)
can supply the expected HEAD `sha`, preserving branch/ruleset enforcement without
requiring `allow_auto_merge`. It still needs a non-bypass identity, fresh required
checks/reviews/threads/rules/security evidence and an atomic shared lease/fencing
contract covering the merge side effect. The REST HEAD precondition alone does
not bind the base or fence another executor. Neither strategy authorizes approval,
bypass, reducing checks or relaxing protection.

## Initial pilot: mentordosnerds/infrastructure only

No consumer is installed by this PR. No other personal or organization repository
is in the rollout. After coordinator approval, prepare a PR **only** in
`mentordosnerds/infrastructure` that pins this reusable to its reviewed immutable
published commit and opts into evaluation. Configure its real review/security
check names and verified App IDs; do not copy placeholder identities. A caller
can use the following shape once those values are resolved:

```yaml
name: Dependabot PATCH evaluation
on:
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review]
permissions: {}
jobs:
  evaluate:
    permissions:
      contents: read
      pull-requests: read
      checks: read
    uses: php-fast-forward/changelog/.github/workflows/dependabot-patch.yml@REVIEWED_FULL_COMMIT_SHA
    with:
      ecosystem: npm
      directory: stacks/services/evolution/mcp
      opt-in: true
      mode: evaluate
      review-security-checks: '[{"name":"ACTUAL_CODE_REVIEW_CHECK","app_id":123},{"name":"ACTUAL_SECURITY_CHECK","app_id":456}]'
```

The example is deliberately not an installable consumer file. Its SHA and App IDs
are placeholders. Dependabot PR tokens are read-only, which is sufficient here.
An early run may block while checks are pending; rerun evaluation after they
finish. A rerun keeps its original event snapshot and blocks if HEAD/base moved.
The verified pilot has npm dependencies in `stacks/services/evolution/mcp`
(exact versions for `@modelcontextprotocol/sdk`, `jose`, `zod`) and
`tests/fixtures/uptimeflare-runtime` (exact `miniflare` version). The former is the
initial proposed evaluation directory; a matching direct stable PATCH changing
manifest and lock is supported, provided metadata/graph stay unchanged. The
latter can be adopted separately in the same pilot after review. Docker,
docker-compose groups, GitHub Actions, pip and Terraform updates in this repository
remain blocked. Verify current lock v3 and real check producers before installing.
Do not broaden support by accepting title labels.

1. Verify the pilot's effective rulesets/branch protection, required checks and
   trusted producers, review and thread requirements, token permissions, existing
   merge actors, and deployment/publication effects. Preserve every existing gate.
2. Exercise synthetic/mocked adversarial fixtures first. In the pilot, observe a
   real supported patch and a mixed/unknown case without merging. Record exact
   workflow, HEAD/base, report and check identities. A candidate is not permission.
3. Obtain coordinator acceptance of the shared lease/fencing interface with n8n
   and the correction Sentinela (task `01a1a650-31c4-73a7-bb56-39b4db2d40ee`).
   There is currently **no accepted lock interface**. A boolean executor selector
   or GitHub Actions concurrency group does not exclude n8n or another service.
4. Implement and test atomic lease acquisition, monotonically increasing fence,
   expiry/revocation, crash recovery, stale-owner rejection and a merge boundary
   that enforces the fence. Select exactly one executor; all others remain read-only.
   Re-read HEAD/base, live effective rules, reviews, unresolved threads, all required
   checks and security/deployment evidence inside that boundary. No bypass token.
5. Separately authorize credentials/permissions/settings if needed, review the
   execution implementation PR, and obtain pilot activation approval. No production
   executor is activated by this evaluation PR. Expand repositories only after
   validation with Felipe.

Rollback before activation is removal/disablement of the pilot caller or setting
`opt-in: false`. After future activation, revoke the shared executor lease/fence
and disable its write identity first, then remove the caller; cancel any armed
native auto-merge individually and verify it is disarmed. Disabling this evaluator
cannot cancel an independently armed merge. Keep rules/checks/reviews intact and
retain the audit evidence. Already merged changes require a reviewed revert.

## Release PRs and publication

A Dependabot dependency PR and a later version PR have different authority and
impact. A release PR is never admitted by name, actor, label or causal ancestry.
Its exact changes must include only independently authorized patch dependencies,
with no unrelated fragments or other changes carried along. Recheck the planned
version, consumed fragments, central history, precise HEAD/base and actual release
configuration. The changelog lifecycle defaults to `publish: true`; merging the
managed version PR can publish real tags/releases. The evaluation workflow neither
admits managed release PRs nor authorizes that publication. A validation trial must
explicitly use `publish: false` in its separately reviewed changelog integration.
