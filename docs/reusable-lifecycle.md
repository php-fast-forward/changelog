# One reusable changelog lifecycle

Use `.github/workflows/changelog.yml` as the consumer entrypoint. It composes the
existing operations, infers the PR revisions and default branch from the event,
validates generated heads and updates one PR comment. No initialization file,
Composer install, Node runtime or consumer shell script is required.

## Start with validation

Create one consumer workflow using a reviewed full product commit. This example
pins the implementation commit that introduced the facade and feedback:

```yaml
name: Changelog

on:
  pull_request:
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled]
  pull_request_target:
    types: [opened, synchronize, reopened, ready_for_review, labeled, unlabeled, closed]
  push:
  workflow_dispatch:

permissions: {}

jobs:
  changelog:
    permissions:
      contents: write
      pull-requests: write
      issues: read
      statuses: write
    uses: php-fast-forward/changelog/.github/workflows/changelog.yml@6a6062a80d28ab69da1e74952fad693f34806fa3
    with:
      prepare-release: false
      publish: false
```

The caller grants the maximum required permissions; each internal job reduces
them. PR-head checks use the ordinary read-only token. Feedback executes the
pinned runtime against data, including fork data, without running consumer
Composer scripts or PHP templates. Publication remains disabled by default.
Dependabot fragment generation remains active; use a disposable pilot PR to
validate its writes before adopting the workflow broadly.

After validating contributions and comments, enable `prepare-release: true` to
exercise the reviewable version PR. Only enable `publish: true` after the pilot
has validated the approved-merge publication contract and publication is
authorized. The workflow never merges its own version PR. `dry-run: true`
previews version/publication operations without their remote content writes;
contribution status and comments remain feedback operations.

## Operation coverage

| Reference Changesets step | Changelog support |
| --- | --- |
| Validate changesets | Existing `check` action/workflow, with exact event head/base. |
| Select mode | The facade routes by event; the version service already distinguishes release, maintenance and no changes. An empty inventory never grants publication. |
| Rehearse versioning | `changelog version --dry-run` and version workflow `dry-run` validate the shared plan without writes; transaction fixtures test actual application separately. |
| Read PR changesets | New `pr-status` Docker action and `github pr-status` CLI operation. The count describes added valid fragments, excluding inherited inventory. |
| Create/update PR comment | New feedback workflow calls the pinned sticky-comment action, in a separate job with PR write permission. |
| Add Dependabot changeset | Existing verified metadata and create-only fragment service; the facade validates the resulting head in the same run. |
| Prepare one version PR | Existing version action/workflow and signed-Bot ownership policy. |
| Validate generated version branch | The facade invokes exact-head feedback after version synchronization, including when a PR event created by `GITHUB_TOKEN` needs approval. |
| Publish reviewed tag/release | Existing publisher, routed from the closed, merged managed PR, with its original head and exact merge SHA. |

The reference uses npm manifests to carry its package versions. This PHP
library intentionally resolves releases from Git tags and committed transaction
evidence, without adding a Composer `version` field or technical files to the
release PR. History maintenance remains available through its individual
workflow and CLI commands.

Dependabot package identifiers use a deliberately restricted character set.
Ordinary Composer, GitHub Actions and Docker names fit that set; names containing
colons, including Maven coordinates such as `org.example:artifact`, are rejected.
The facade does not claim unrestricted support for every Dependabot ecosystem.

## Comments and failure behavior

`comment-mode` accepts:

| Mode | Behavior |
| --- | --- |
| `always` (default) | Create or update one comment after successful or failed checks. |
| `on-failure` | Create/update on failure. On recovery, update a previous failed comment without creating a new success-only comment. |
| `never` | Record validation without creating or updating comments. |

The preview includes the checked head/base, policy, added fragment categories
and semantic impact, and actionable diagnostics. Text from PR files is escaped,
mentions/autolinks are suppressed and the preview is bounded. Full historical
Markdown is never rewritten for feedback. Operational failures have a controlled
fallback comment; unavailable evidence is never described as a successful check.
Moved, retargeted or closed PRs do not receive stale feedback. Cancellation does
not attempt to post a comment.

The CLI writes JSON feedback even for an invalid contribution and exits with
status 1. The workflow preserves that failure while allowing the comment job to
run. A comment cannot waive the contribution gate.

## Generated heads and required status

The trusted feedback operation records a fixed commit status named
`Fast Forward Changelog`, attached to the exact PR head. It records `pending`
before validation and confirms the live PR/head/base again before recording
`success` or `failure`. Uncertain execution leaves a blocking pending status.
The checked accepted fragment bytes must match committed regular blobs.

Require this exact status in the pilot repository's branch protection, with
up-to-date checks. Do not mistake a green default-branch workflow run for a
successful check of the generated head. Status, permissions, signed ownership
and merge settings must be observed on the GitHub platform during the pilot.

This validates the changelog contribution directly in the current run. Other
consumer tests still follow GitHub's token/event rules: runs created by
`GITHUB_TOKEN` may require approval. A separately configured App token can enable
their normal event-driven CI. An optional App token may be supplied as
`secrets.token`; its Bot identity must match `automation-actor`.

## Optional settings and composition

`base-branch` defaults to the repository's default branch, supporting `main` and
`master` without another file. The facade also accepts `fragment-directory`,
`changelog-file`, `locale`, `tag-prefix`, `source`, `managed-branch`,
`automation-actor`, `waiver-label` and `maintenance-label`. Keep these settings
consistent across all operations. PR operations work at repository root; select
nested packages through repository-relative managed paths.

Outputs expose the version PR number/head and published version/tag/SHA. They
allow a consumer to attach its own post-publication tasks. Docker image
publication is product-specific and remains separate. The packaged runtime is
still built from its pinned Dockerfile; publishing an image does not implicitly
switch the actions to consuming GHCR.

The individual reusable workflows remain supported for advanced composition;
the facade does not replace their business services. See [the complete operation
contracts](github-actions.md), [publication](publication.md) and
[isolated workflow fixtures](../tests/Workflow/README.md).

## Pilot acceptance before release

Validate PR success and missing/malformed fragments, a fork, waiver grant/removal,
Dependabot and grouped dependency updates, version synchronization on a newer
base, generated-head status, comment recovery, reruns and cancellation. Inspect
the effective token permissions and required-status association. The version PR
must change only central history and consumed fragment deletions, with no
`Unreleased`, generated metadata comments or plan files.

Rehearse publication with `dry-run` and verify the approved target and exact notes.
Publish real tags/releases only after that pilot and explicit authorization.
Local fixtures, 100% coverage and a code review do not replace this platform test.
