# GitHub Actions for consumers

The five reusable workflows run the same PHP services as the local CLI. A
contribution adds a fragment; version automation opens one managed PR; approval
and merge precede publication of its verified tag and GitHub Release.
History maintenance is a separate operation.

The examples below pin the reviewed full product commit `7c1eb45d41a5f77dd8d93ab8a58522e10f4667ce`. Keep every consumer reference immutable when updating the runtime.
The workflows check out their own runtime using the called workflow's immutable
`job.workflow_repository` and `job.workflow_sha`, separately from consumer data.
The caller's `github` context still identifies the consumer. This follows
[GitHub's reusable-workflow contexts](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts)
and [caller context rules](https://docs.github.com/en/actions/reference/workflows-and-actions/reusing-workflow-configurations).
A platform lacking those runtime identity fields fails before checkout.

## Shared configuration

| Input | Default | Meaning |
| --- | --- | --- |
| `working-directory` | `.` | Directory below the consumer checkout. PR operations require exactly `.`. |
| `fragment-directory` | `.changelog` | Pending fragments, relative to the working directory. |
| `changelog-file` | `CHANGELOG.md` | The single central history, relative to the working directory. |
| `locale` | `en` | Structural presentation: `en` or `pt-BR`. |
| `template` | `keep-a-changelog` | Built-in template or an explicit trusted PHP template path. Checks accept only the built-in template. |
| `base-ref` | `HEAD` | Local planning revision; keep `HEAD` for automation. |
| `tag-prefix` | `v` | Exact prefix for stable SemVer tags. |
| `repository` | Caller repository | GitHub `owner/name`; normally omit this override. |
| `source` | `auto` | Backfill source: `auto`, `github` or `tags`. |

The optional `secrets.token` defaults to `github.token`. Each job requests its
own minimum permissions, and the caller must permit those permissions. A caller
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
    uses: php-fast-forward/changelog/.github/workflows/changelog-check.yml@7c1eb45d41a5f77dd8d93ab8a58522e10f4667ce
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
    uses: php-fast-forward/changelog/.github/workflows/changelog-dependabot.yml@7c1eb45d41a5f77dd8d93ab8a58522e10f4667ce
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
[Dependabot policy details](policies.md#dependabot-fragments) and
[GitHub's automation guidance](https://docs.github.com/en/code-security/tutorials/secure-your-dependencies/automate-dependabot-with-actions).

Repository Actions execution policies must permit these base-controlled
`pull_request_target` jobs; GitHub documents the policy controls in
[workflow execution protections](https://docs.github.com/en/actions/how-tos/administer/control-workflow-execution).
The reusable workflows still verify the event and use trusted checkouts.

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
    uses: php-fast-forward/changelog/.github/workflows/changelog-version.yml@7c1eb45d41a5f77dd8d93ab8a58522e10f4667ce
    with:
      base-branch: main
      managed-branch: changelog/version
      automation-actor: github-actions[bot]
      title: 'chore: update changelog'
      dry-run: false
```

The job checks out the fresh base and compares it with GitHub before planning.
It creates or updates one same-repository managed PR. Updates require the
configured Bot's verified signed head, transaction trailers and exact allowed file scope;
human edits are refused. It serializes with publication for the same repository
and base branch. Repeated unchanged plans produce no extra commits, empty plans
produce no new PR, and a merged consolidation awaiting publication blocks a second
version transaction. A maintenance-only PR has no next public version. See
[the version PR transaction](version-pull-request.md).

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
    uses: php-fast-forward/changelog/.github/workflows/changelog-publish.yml@7c1eb45d41a5f77dd8d93ab8a58522e10f4667ce
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
    uses: php-fast-forward/changelog/.github/workflows/changelog-history.yml@7c1eb45d41a5f77dd8d93ab8a58522e10f4667ce
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
| `changelog-dependabot` | `status`, `path`, `head-sha` |
| `changelog-version` | `status`, `version`, `pull-request`, `url`, `head-sha`, `plan-id`, `maintenance` |
| `changelog-publish` | `state`, `version`, `tag`, `sha`, `url` |
| `changelog-history` | `status` |

Caller jobs can read `${{ needs.changelog.outputs.result }}` and parse it with
`fromJSON(...)`. A refused transaction fails the workflow with its diagnostic;
there is no public skip flag. Consumer-defined approval, review and merge rules
remain the gates between the version PR and publication.
