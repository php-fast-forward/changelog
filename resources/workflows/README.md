# Consumer workflow

Copy [changelog.yml](changelog.yml) to `.github/workflows/changelog.yml` in the
consumer repository. It is a caller example, not the reusable implementation.
GitHub does not execute the example while it remains in `resources/workflows`.

Before enabling it:

1. Replace `REVIEWED_COMMIT_SHA` with a full, reviewed commit already integrated
   into this package's release line and containing the tested reusable workflow.
   The placeholder deliberately does not identify a published version. Do not
   pin a temporary PR branch or its unmerged commit.
2. Adjust both event branch filters and `base-branch` if the consumer's release
   branch is not `main`.
3. Keep `publish: false` during validation. The example checks contributions,
   updates a sticky PR comment, authors verified Dependabot fragments and
   prepares one reviewable version PR. It never merges that PR.

`vars.CI_LINUX_RUNNER` may contain a JSON array of labels for an authorized
self-hosted Linux runner. Without that variable, jobs use `ubuntu-latest`.
Docker, Bash and Git are required. The workflow does not detect billing limits
or configure runner access.

The caller grants the maximum token permissions needed by the lifecycle; the
reusable jobs narrow them for each operation. PR-head checks remain read-only.
PR feedback runs the pinned trusted implementation against PR data, without
executing consumer PHP templates or Composer scripts. Use the event set intact
to keep read-only checks separate from trusted feedback and publication routing.

The default `github.token` is sufficient when repository policy allows Actions
to create PRs. If an approved App token is needed for downstream CI events, pass
it as the optional reusable secret `token` and configure `automation-actor` to
the same Bot identity. Do not add a token value to this file.

To rehearse checks and comments without preparing a version PR, set
`prepare-release: false`. Enable real publication only after reviewing the
approved-merge contract and obtaining publication authority.

See the [automation map](../../docs/automation-map.md) to distinguish public
reusables, internal repository workflows and lower-level actions. The
[lifecycle guide](../../docs/reusable-lifecycle.md) documents settings, feedback
and pilot acceptance; the [operation contracts](../../docs/github-actions.md)
cover advanced composition.
