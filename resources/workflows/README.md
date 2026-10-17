# Consumer workflow

Copy [changelog.yml](changelog.yml) to `.github/workflows/changelog.yml` in the
consumer repository. It is a caller example, not the reusable implementation.
GitHub does not execute the example while it remains in `resources/workflows`.

Before enabling it:

1. Review the full product commit already selected in the example. It is an
   integrated revision containing the tested reusable workflow, not a claim that
   a tagged release exists. Update it deliberately after validation; do not pin
   a temporary PR branch or its unmerged commit.
2. Adjust both event branch filters and `base-branch` if the consumer's release
   branch is not `main`.
3. Keep `publish: false` during validation. The example checks contributions,
   updates a sticky PR comment, authors verified Dependabot fragments and
   prepares one reviewable version PR. It never merges that PR.

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

To rehearse checks and comments without preparing a version PR, set
`prepare-release: false`. Enable real publication only after reviewing the
approved-merge contract and obtaining publication authority.

See the [automation map](../../docs/automation-map.md) to distinguish public
reusables, internal repository workflows and lower-level actions. The
[lifecycle guide](../../docs/reusable-lifecycle.md#optional-settings-and-composition)
lists every optional input and its default, feedback and pilot acceptance.
The [operation contracts](../../docs/github-actions.md) cover advanced composition.
