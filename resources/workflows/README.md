# Consumer workflow

Copy [changelog.yml](changelog.yml) to `.github/workflows/changelog.yml` in the
consumer repository. It is a caller example, not the reusable implementation.
GitHub does not execute the example while it remains in `resources/workflows`.

Before enabling it:

1. The example follows published `0.x` releases through the `v0` branch.
   Minor updates can change contracts. Select a complete release tag or reviewed
   full commit when you need a fixed implementation.
2. Adjust both event branch filters and `base-branch` if the consumer's release
   branch is not `main`.
3. The example checks contributions, updates a sticky PR comment, authors
   verified Dependabot fragments and prepares one reviewable version PR. It
   never merges that PR. Publication defaults to enabled: merging the reviewed
   version PR creates its exact tag and GitHub Release.
   The caller sets `publish: true` explicitly to preserve that intent when using
   an older published revision whose default was disabled. Verify defaults and
   supported inputs at the selected reusable ref.

Public repositories must also review the consumer's [Actions event policy](../../docs/actions-event-policy.md)
to permit the trusted `pull_request_target` route for this caller.

Jobs use `ubuntu-latest` by default; no repository variable is required.
Optionally pass `runs-on` as a JSON array of Linux runner labels, for example
`runs-on: '["self-hosted", "Linux", "X64"]'`. Docker, Bash and Git are required.
Runner selection and access belong to the consumer's infrastructure.

The caller grants the maximum token permissions needed by the lifecycle; the
reusable jobs narrow them for each operation. PR-head checks remain read-only.
PR feedback runs the pinned trusted implementation against PR data, without
executing consumer PHP templates or Composer scripts. Use the event set intact
to keep read-only checks separate from trusted feedback and publication routing.

The default `github.token` can create the managed PR when repository policy
allows it; that PR's downstream workflows require approval. To run them
automatically, configure `app-client-id`, the optional secret `app-private-key`
and `automation-actor` with the App's Bot login. Each trusted job creates its own
token. An already issued compatible Bot token can instead be passed as
`secrets.token`; do not combine both routes or put credential values in YAML.
See [App credentials and installation](../../docs/github-app.md).

For a validation-only trial, explicitly set `publish: false`. The version PR
still consolidates history, but its merge creates neither tag nor GitHub Release.
Later version PRs continue from the recorded version; disabling publication does
not erase that state. To rehearse checks and comments without preparing a version
PR, also set `prepare-release: false`. Review the approved-merge contract before
merging a version PR in the normal automatic-publication flow.

See the [automation map](../../docs/automation-map.md) to distinguish public
reusables, internal repository workflows and lower-level actions. The
[complete reference](../../docs/workflow-reference.md) lists every input,
default, output and secret. The [lifecycle guide](../../docs/reusable-lifecycle.md)
explains feedback, publication choices and validation.
The [operation contracts](../../docs/github-actions.md) cover advanced composition.
