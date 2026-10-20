# GitHub Actions for consumers

Start with [one reusable lifecycle](reusable-lifecycle.md) for the normal
contribution, Dependabot, version and approved-merge flow, including PR feedback
and exact-head status. The individual workflows below remain available for
advanced composition. The lifecycle publishes approved version merges by
default; use explicit `publish: false` while validating an integration.

The **[workflow and Action reference](workflow-reference.md)** lists every
input, type, default, required value, secret, output and permission. This page
focuses on complete event callers and the behavior of each operation.

Choose only the operations your custom workflow needs. Do not add these callers
alongside the lifecycle for the same events: that would run the operation twice.
The examples below use release `v0.1.0` and require a Linux runner with Docker.
They run the packaged CLI separately from consumer files and dependencies.
See [runtime paths and identity](docker.md) for the Action implementation contract.

## Shared configuration

Shared defaults and applicability differ between the lifecycle, individual
workflows and step-level Docker Actions. Check the
[shared workflow inputs](workflow-reference.md) or
[Action inputs](workflow-reference.md) for your selected
entrypoint. In particular, the lifecycle uses `version-pr-title`, the Version
workflow/Action uses `title`, and the History workflow defaults to a preview
while the History Action applies locally by default.

The trusted write/feedback workflows also accept optional `app-client-id` and
`secrets.app-private-key`. Each job can generate a separate scoped installation
token to trigger downstream PR CI automatically. The read-only contribution
workflow does not accept the App key. Configure the Bot identity consistently;
see [credentials, permissions and migration](github-app.md).

Alternatively supply an already issued Bot token as `secrets.token`. Do not
combine it with the App pair. Without either route, jobs use `github.token`.
Each example uses the default token route. Each job requests its own minimum
permissions, and the caller must permit those permissions. A caller
cannot grant permissions withheld by repository or organization policy. Creating
PRs with `GITHUB_TOKEN` also requires the repository's
[Actions PR creation setting](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/enabling-features-for-your-repository/managing-github-actions-settings-for-a-repository).

Use `working-directory: '.'` for check, Dependabot, version and merged-PR publish.
For a monorepo package, pass `fragment-directory: packages/lib/.changelog` and
`changelog-file: packages/lib/CHANGELOG.md`. These paths must agree across all
four operations. GitHub file APIs address the repository root. The history
workflow can use a nested working directory because it only maintains the
checked-out trusted base locally.

A PHP template is executable configuration. Version, publication and history
load it only from their checked-out trusted base or approved merge target, using
an explicit path. Check never executes it. None of these workflows install or
execute consumer Composer dependencies, scripts or PR-head PHP. They install
only the separately pinned Changelog runtime without Composer plugins/scripts.

## Contribution check

Create `.github/workflows/changelog-check.yml` in the consumer:

```yaml
name: Changelog contribution
on:
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled]
permissions: {}
jobs:
  changelog:
    permissions:
      contents: read
      pull-requests: read
      issues: read
    uses: php-fast-forward/changelog/.github/workflows/changelog-check.yml@v0.1.0
    with:
      pull-request: ${{ github.event.pull_request.number }}
      head-sha: ${{ github.event.pull_request.head.sha }}
      base-sha: ${{ github.event.pull_request.base.sha }}
```

The workflow requires the exact PR/head/base values from a `pull_request` event
and checks out only that head with read permissions and no persisted credentials.
It compares the contribution with `base-sha`. A stale live PR head fails the
shared check. The public labels `changelog-not-required` and
`changelog-maintenance` are requests: the latest label grant must be by a current
maintainer/admin to authorize a waiver or central-history edit. Editable PR text
and copied commit trailers confer no authority. See [the policy contract](policies.md).

## PR status and sticky feedback

For feedback without the full lifecycle, create a trusted caller:

```yaml
name: Changelog PR Feedback

on:
  pull_request_target:
    branches: [main]
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled]

permissions: {}

jobs:
  changelog:
    permissions:
      contents: read
      pull-requests: write
      issues: read
      statuses: write
    uses: php-fast-forward/changelog/.github/workflows/changelog-pr-status.yml@v0.1.0
    with:
      pull-request: ${{ github.event.pull_request.number }}
      head-sha: ${{ github.event.pull_request.head.sha }}
      base-sha: ${{ github.event.pull_request.base.sha }}
      comment-mode: always
```

This workflow validates exact PR identities, sets `Fast Forward Changelog` and
updates one sticky comment. `comment-mode` accepts `always`, `on-failure` or
`never`. It uses the trusted runtime and reads PR data without executing PR PHP,
templates or Composer scripts. A failed contribution can still receive feedback.
After an automated dependency commit, pass its verified new head; omitting
`base-sha` resolves the live PR base before validation. Keep the comparison
immutable within that validation. Do not add this caller alongside the lifecycle's
feedback for the same events.

## Dependabot fragments

Use a separate workflow with the base-controlled `pull_request_target` event:

```yaml
name: Dependabot changelog
on:
  pull_request_target:
    types: [opened, synchronize, reopened]
permissions: {}
jobs:
  changelog:
    if: github.event.pull_request.user.login == 'dependabot[bot]'
    permissions:
      contents: write
      pull-requests: read
    uses: php-fast-forward/changelog/.github/workflows/changelog-dependabot.yml@v0.1.0
    with:
      pull-request: ${{ github.event.pull_request.number }}
      head-sha: ${{ github.event.pull_request.head.sha }}
      include-dev: true
      include-actions: true
```

The workflow checks out the PR base, then collects dependency names, scope and
ecosystem with the pinned official `dependabot/fetch-metadata` action. Its actor
and commit checks stay enabled. The service independently confirms the live
Dependabot Bot identity, same-repository head and supplied SHA before creating
one deterministic fragment in that PR. It never executes head content. Fork
writes, stale heads and unknown existing fragment contents are refused; the
same generated fragment is idempotent. The default is `Changed` with a patch
impact, including development dependencies and GitHub Actions.

`security-alert-numbers` accepts a JSON array, default `[]`, only from trusted
metadata associating those alerts with this PR. Do not derive it from PR body,
labels or arbitrary head files. The service verifies every supplied alert is
currently open and matches an updated dependency before producing `Security`.
That API needs an appropriately scoped token with
[Dependabot alerts read permission](https://docs.github.com/en/rest/dependabot/alerts#get-a-dependabot-alert).
Missing permissions or inconsistent alerts fail with a diagnostic. See
[Dependabot policy details](policies.md) and
[GitHub's automation guidance](https://docs.github.com/en/code-security/tutorials/secure-your-dependencies/automate-dependabot-with-actions).

Repository Actions execution policies must permit these base-controlled
`pull_request_target` jobs. See [Actions event policy setup](actions-event-policy.md)
for the default public-repository restriction, Evaluate versus Active results,
and a policy scoped to the consumer caller. The reusable workflows still verify
the event and use trusted checkouts.

The write token must be permitted by the repository's execution policy. GitHub
restricts `pull_request_target` when the base ref itself was created by
Dependabot; this differs from the ordinary maintained-base pattern used by
[the official fetch-metadata examples](https://github.com/dependabot/fetch-metadata#usage-instructions).
When an organization policy limits the default token but secrets remain
available, pass an authorized scoped Bot/App token through the reusable
workflow's `token` secret. In the special Dependabot-created base-ref case,
GitHub also withholds secrets: an additional secret cannot fix that run. Use
a maintained base or a separately authorized trusted trigger instead.
Declaring `contents: write` does not override these platform restrictions. See
[GitHub's Dependabot event restrictions](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-on-actions).

## One version PR per release line

Run from a push to the maintained base, or a manual dispatch:

```yaml
name: Changelog version PR
on:
  push:
    branches: [main]
  workflow_dispatch:
permissions: {}
jobs:
  changelog:
    permissions:
      contents: write
      pull-requests: write
      issues: read
    uses: php-fast-forward/changelog/.github/workflows/changelog-version.yml@v0.1.0
    with:
      base-branch: main
      managed-branch: changelog/version
      automation-actor: github-actions[bot]
      title: 'chore(release): fast-forward/example v{current_version} → v{next_version}'
      dry-run: false
```

The job checks out the fresh base and compares it with GitHub before planning.
It creates or updates one same-repository managed PR. Updates require the
configured Bot's verified signed head, transaction trailers and exact allowed file scope;
human edits are refused. It serializes with publication for the same repository
and base branch. Repeated unchanged plans produce no extra commits, empty plans
produce no new PR. The baseline is the highest stable version in the selected
base's central history and ancestral tags: an earlier merged consolidation
without a tag/release does not block a new version PR. Previous history remains
intact; only the current plan's fragments are consumed.
A maintenance-only PR has no next public version. See
[the version PR transaction](version-pull-request.md).

The version workflow's `title` accepts a literal title or the placeholders
`{repository}`, `{repository_name}`, `{current_version}` and `{next_version}`. The lifecycle exposes the same setting as
`version-pr-title`; see [title settings](reusable-lifecycle.md).
The default is `chore(release): {repository} v{current_version} → v{next_version}`. A title-only refresh preserves
the signed branch head and transaction identity.

Before merging, the generated source base must equal the live PR base. A later
base push resynchronizes the same PR and includes the newly accepted fragments;
the contribution check refuses a stale generated head. Make that check required
and enable strict up-to-date status checks in branch protection or the equivalent
ruleset. These are consumer repository settings, not permissions that a workflow
can grant itself. GitHub documents
[required status checks](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches#require-status-checks-before-merging).

Set `dry-run: true` to plan without GitHub writes. Changing `automation-actor`
requires a token authenticated as that Bot; a different name in an input does
not establish identity. An optional short-lived App installation token may be
passed through `secrets.token` when the caller securely supplies one. With the
default `GITHUB_TOKEN`, GitHub currently starts generated `pull_request`
opened/synchronize/reopened runs in an approval-required state; most other
resulting events do not start runs. Use an App token when automatic downstream
CI is required, following [GitHub's token event rules](https://docs.github.com/en/actions/concepts/security/github_token).

## Publish the approved merge

Publish only from the closed, merged managed PR event:

```yaml
name: Changelog publish
on:
  pull_request_target:
    types: [closed]
permissions: {}
jobs:
  changelog:
    if: >-
      github.event.pull_request.merged == true &&
      github.event.pull_request.head.ref == 'changelog/version' &&
      github.event.pull_request.base.ref == 'main'
    permissions:
      contents: write
      pull-requests: read
    uses: php-fast-forward/changelog/.github/workflows/changelog-publish.yml@v0.1.0
    with:
      base-branch: main
      pull-request: ${{ github.event.pull_request.number }}
      target-sha: ${{ github.event.pull_request.merge_commit_sha }}
      expected-head-sha: ${{ github.event.pull_request.head.sha }}
      managed-branch: changelog/version
      automation-actor: github-actions[bot]
      dry-run: false
```

The checkout is the approved merge target, never the PR head. Event values must
match the supplied PR, original head and merge target. The runner also fetches
the live merged PR and verifies repository, branches, Bot ownership, signed head
and committed consolidation against that target before tag/release creation. The tag resolves to
that approved commit and the notes come from its central history. A maintenance
consolidation creates no public release. `dry-run: true` validates without publication.
See [publication](publication.md) and [local recovery](release-receipts.md).

## Backfill or format trusted history

This manual workflow previews the maintained base by default:

```yaml
name: Changelog history preview
on:
  workflow_dispatch:
    inputs:
      operation:
        type: choice
        options: [backfill, format]
        default: backfill
permissions: {}
jobs:
  changelog:
    permissions:
      contents: read
    uses: php-fast-forward/changelog/.github/workflows/changelog-history.yml@v0.1.0
    with:
      base-branch: main
      operation: ${{ inputs.operation }}
      dry-run: true
      check: false
```

`backfill` imports only missing stable tagged versions, preserving existing
sections. `format` reformats existing structural presentation without GitHub
history import. Set both `check: true` and `dry-run: false` to fail when the
selected operation would change history, without writing. The two read-only
modes cannot be enabled together. Setting both `dry-run: false` and `check: false`
applies locally on the disposable runner; this workflow has no commit or push
step. Review an adoption change locally and
submit it through the usual PR process. See [history preservation](history.md).

## Outputs

Every workflow exposes `result`, a JSON object from the shared service. Other
outputs are strings and are empty when the operation does not provide them.

| Workflow | Additional outputs |
| --- | --- |
| `changelog-check` | `status`, `fragments` |
| `changelog-pr-status` | `status` |
| `changelog-dependabot` | `status`, `path`, `head-sha` |
| `changelog-version` | `status`, `version`, `pull-request`, `url`, `head-sha`, `plan-id`, `maintenance` |
| `changelog-publish` | `state`, `version`, `tag`, `sha`, `url` |
| `changelog-history` | `status` |

Caller jobs can read `${{ needs.changelog.outputs.result }}` and parse it with
`fromJSON(...)`. A refused transaction fails the workflow with its diagnostic;
there is no public skip flag. Consumer-defined approval, review and merge rules
remain the gates between the version PR and publication.

---

[Documentation index](index.md)
