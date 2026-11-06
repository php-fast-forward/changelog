# Opt-in PATCH execution

The read-only `dependabot-patch.yml` contract is preserved. The separate
`dependabot-automerge.yml` reusable advances an update through real live gates
and an execution boundary. Both default to opt-out/evaluation. No caller,
installation, App permissions, repository variable or branch protection is
installed by this PR. Initial adoption remains exclusively
`mentordosnerds/infrastructure`, subject to separate coordinator approval.

## Implemented path

The execution reusable accepts a trusted default-branch `workflow_dispatch` or
`workflow_run` caller. A workflow-run's HEAD must match the fetched PR. It checks
out only the called workflow's immutable runtime; it does not check out consumer
code, install its packages, load artifacts or execute its scripts. A consumer can
wake it after CI/security checks, including failures for repair assessment. A
fresh event or manual dispatch is required after HEAD/base changes; evaluation
is one iteration, not a background daemon.

`advanceDependencyUpdate` invokes the existing complete PATCH proof, then the
live GitHub adapter reads traditional protection and effective active rules.
Unknown rules, inaccessible evidence, unsupported legacy status identities and
incomplete reviews block. Supported policies require admin enforcement, strict
up-to-date status checks, stale-review dismissal, last-push approval and resolved
conversations. Required checks must bind names to App IDs; configured review,
security and viability checks must also be server-required. Missing, ambiguous,
spoofed, pending or failed checks do not authorize merging.

The adapter validates the premerge test commit's two parents against exact
HEAD/base. Distinct trusted security/viability checks must succeed on that test
commit, in addition to exact-HEAD evidence. It reads aggregate review state,
current human approvals by distinct identity and resolved threads, including
thread pagination. More than 100 review submissions currently block rather than
silently accept incomplete approval evidence. It re-reads the PR after inspection.
The controller repeats both PATCH proof and gates inside ownership before merge.
It never submits a formal approval.

States are `evaluated`, `waiting`, `blocked`, `repairable`, `repair-requested`,
`merged` and `reconciliation-required`. A trusted App-bound required CI failure
can be handed to an explicit `repair.request` adapter; no repair service or
credential is created. Without a handler the failure remains repairable. Retry
requires fresh immutable input and a complete reinspection. An uncertain write
response requires remote reconciliation, never a blind second merge. A supplied
durable ledger receives the exact authorized patch/merge receipt; the workflow
also emits its receipt in the result. No ledger backend is presumed deployed.

## Native boundary and actual exclusion

There is no deployed cross-n8n lease or lease endpoint. The native adapter instead
requires **existing** classic branch protection with exactly one allowed App ID,
empty allowed user/team lists, admin enforcement, strict up-to-date checks,
force-push disabled and deletion disabled. Review bypass allowances block.
Classic push restrictions are an organization-repository capability. Personal
repositories can use evaluation and the programmatic state/proof adapters, but
execution waits for a separately verified exclusion adapter if this capability
is unavailable. No unsupported owner type is silently treated as exclusive.

The repository variable `DEPENDABOT_MERGE_EXECUTOR` must exactly equal
`github-actions:<authenticated App ID>`. Another executor must use another App
identity; sharing this App's credentials with n8n is unsupported. Adoption must
verify credential exclusivity before selecting this route.

The existing official `actions/create-github-app-token@v3.3.0` component mints a
repository-scoped, job-local token from already approved credentials and revokes
it after the job. Its authenticated slug is resolved to the App ID. The boundary
re-reads selection/protection and binds every merge command to the owned
repository/PR/HEAD/base. The REST adapter sends the expected HEAD `sha`.

GitHub enforces current branch restrictions and up-to-date requirements at the
merge side effect. This is the native base-race protection; the REST API does
not provide a base-SHA or software fencing CAS. The implementation does not
claim an external atomic lease. Repositories lacking these restrictive native
conditions block and need a separately implemented accepted boundary or merge
queue adapter. Merge queues are currently an unsupported effective rule and
block; this code never bypasses them.

Changing the variable alone is **not** instantaneous revocation of an in-flight
token. For disablement or switching, first revoke the old installation's access
or change the server-enforced allowed App, verify the old identity cannot merge,
then update the selector and enable the new executor. Keep all checks/reviews.
For a repository using this native boundary, a different n8n App cannot write to
the protected branch even if n8n is concurrently running. GitHub concurrency
is not used as cross-service exclusion.

The new token contract requests contents/PR write and checks/variables/
administration read. It cannot grant permissions absent from its installation.
In particular, Administration read is needed to inspect traditional protection.
No permission expansion is performed here: missing approvals/credentials/access
are runtime blockers and must be separately authorized before adoption.
`allow_auto_merge` is untouched and not required for the direct merge route.

## Managed version integration

`advanceManagedVersion` is a separate programmatic path. It never admits a PR by
its release-like title or branch name. It checks the configured Bot login and
numeric ID and uses `managedOwnershipInspector` to invoke the existing `github pr-status`
validator with exact HEAD/base, managed branch/actor and `publish-status=false` (the PR-status operation does
not accept a dry-run flag). It requires its valid managed-version result, not a PR label or
caller flag. Missing identity/runtime/ledger wiring produces waiting.

`compileVersionPlan` invokes the actual packaged Changelog CLI on a clean exact
base snapshot, with the built-in template, local tags and PR resolution disabled.
It never invokes a consumer's vendor binary or Composer. The caller must supply
a trusted runtime path outside the consumer data and a read-only disposable base
checkout. The compiler environment omits credentials. Its trusted JSON plan
contains the output digest, versions and consumed SHA-256 fragment map; absolute
paths are normalized below the snapshot root.

`managedVersionInspector` reads complete immutable base/head trees and changed
GitHub blobs, and obtains authorization records from an explicit durable ledger.
`proveManagedPatchRelease` requires the next stable PATCH, no backfill/resume,
exact equality of consumed fragments to authorized patch receipts, the compiled
history digest, exact fragment deletions and no other content/mode/path change.
A receipt's fragment association must be established by the trusted authorization
producer; Markdown PR references alone are not authority. A valid ordinary
Changelog plan consuming all pending notes is insufficient.

Because `publish:true` can publish tags/releases after the version PR merges,
publication authority must be a distinct ledger record bound to repository,
version-PR HEAD, base, plan ID, output digest and next version. It is not inferred
from dependency PATCH authority. The controller re-compiles/rechecks the ledger
before merge and applies the same live gates/boundary. No release or tag is
published by these tests.

The public execution workflow currently wires the dependency path; managed
version execution is exposed through the tested programmatic adapters. A durable
receipt/fragment authorization backend and existing managed ownership adapter
must be supplied before wiring that path into a pilot caller. No fake ledger,
boolean approval, endpoint or third service is installed to fill that gap.

## Validation and adoption gates

Fixtures use synthetic SDKs for real gate/merge adapter calls and the real CLI
in a disposable Git snapshot. They exercise successful execution, ownership
mismatch, changed snapshots, required/security check failures, wrong test-merge
parents, unknown rules, inaccessible protection, unresolved threads, uncertain
writes, repair requests, release piggyback changes and missing publication
approval. No real merge, token mint or publication is performed.

Before pilot activation, separately review this exact runtime revision; verify
its actual ecosystem/constraints, real check producers and merge-commit checks;
authorize any required existing App access; audit competing writers and secret
holders; verify exclusive server restrictions and safe switch-off order; select
one executor; then review an evaluation-only pilot caller. Only after those
checks should activation be authorized. Other repositories remain out of scope.

## Caller shape for the pilot's evaluation trial

After separately reviewing the real check producers, a pilot-only adoption PR
can use this shape. The full runtime SHA, workflow name and App IDs below are
placeholders, not installed configuration. A workflow-run with zero/multiple PR
associations should be skipped or dispatched explicitly; never guess a PR by title.

```yaml
name: Inspect dependency PATCH execution
on:
  workflow_dispatch:
    inputs:
      number:
        type: number
        required: true
  workflow_run:
    workflows: ['ACTUAL_AUDITED_CI_WORKFLOW']
    types: [completed]
permissions: {}
jobs:
  advance:
    if: github.event_name == 'workflow_dispatch' || (github.event.workflow_run.pull_requests[0].number != null && github.event.workflow_run.pull_requests[1].number == null)
    permissions:
      contents: read
      pull-requests: read
      checks: read
    uses: php-fast-forward/changelog/.github/workflows/dependabot-automerge.yml@REVIEWED_FULL_COMMIT_SHA
    with:
      pull-request: ${{ github.event.workflow_run.pull_requests[0].number || inputs.number }}
      opt-in: true
      mode: evaluate
      ecosystem: npm
      directory: stacks/services/evolution/mcp
      review-security-checks: '[{"name":"ACTUAL_REVIEW","app_id":123},{"name":"ACTUAL_SECURITY","app_id":456}]'
      pair-checks: '[{"name":"ACTUAL_SECURITY","app_id":456},{"name":"ACTUAL_VIABILITY","app_id":789}]'
```

Before choosing `mode: execute`, separately authorize and verify the App installation
and secret route, critical server-required checks, exclusive native restrictions,
selector and shutdown procedure described above. The caller would then explicitly
supply `app-client-id` and the scoped `app-private-key` secret. This PR does not
supply those values, create credentials, grant installed permissions or activate
that route. The coordinator owns review requests; no CodeRabbit request is made
by this implementation.
