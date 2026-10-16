# Automation map

A consumer normally copies one [caller example](../resources/workflows/changelog.yml)
and references one reusable: `.github/workflows/changelog.yml`. The files in this
repository have three responsibilities: consumer interfaces, this package's own
event workflows, and implementation actions.

## Consumer entrypoint and optional operations

All seven public reusable paths remain stable:

| Reusable workflow | Responsibility |
| --- | --- |
| [`changelog.yml`](../.github/workflows/changelog.yml) | Recommended entrypoint: routes contribution checks, PR feedback, Dependabot fragments, version preparation and optional publication. |
| [`changelog-check.yml`](../.github/workflows/changelog-check.yml) | Read-only contribution validation at the exact PR head and base. |
| [`changelog-pr-status.yml`](../.github/workflows/changelog-pr-status.yml) | Trusted exact-head status and one sticky PR comment. |
| [`changelog-dependabot.yml`](../.github/workflows/changelog-dependabot.yml) | Verified dependency metadata and a deterministic dependency-update fragment. |
| [`changelog-version.yml`](../.github/workflows/changelog-version.yml) | Create or synchronize the single reviewable version PR. |
| [`changelog-publish.yml`](../.github/workflows/changelog-publish.yml) | Verify and publish the exact approved consolidation commit. |
| [`changelog-history.yml`](../.github/workflows/changelog-history.yml) | Explicit backfill or formatting maintenance in a reviewable history PR. |

The six individual operations are available for advanced composition; ordinary
adoption needs only the entrypoint. The facade does not schedule historical
backfill or formatting automatically. See [the lifecycle guide](reusable-lifecycle.md)
and [operation contracts](github-actions.md).

GitHub requires reusable workflow files directly in `.github/workflows` and
does not support a subdirectory there. Moving their implementation into
`resources` would make the references unusable. See [GitHub's reusable workflow
requirements](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows#creating-a-reusable-workflow).
The [copyable caller](../resources/workflows/README.md) belongs in `resources`
because it is an adoption example; GitHub executes it only after a consumer
copies it into its own `.github/workflows` and selects a reviewed product commit.

## This repository's workflows

These event workflows operate on this package and are not consumer templates:

| Repository workflow | Trigger and purpose |
| --- | --- |
| [`tests.yml`](../.github/workflows/tests.yml) | PR, push and manual checks for this package: quality, tests, coverage and installed-runtime fixtures. |
| [`check-changelog.yml`](../.github/workflows/check-changelog.yml) | PR contribution gate using the local action under read permissions. |
| [`dependabot-changelog.yml`](../.github/workflows/dependabot-changelog.yml) | Trusted Dependabot PR event creates this package's dependency fragment. |
| [`release-changelog.yml`](../.github/workflows/release-changelog.yml) | A push to `main` prepares this package's version PR. |
| [`publish-changelog.yml`](../.github/workflows/publish-changelog.yml) | A merged managed version PR verifies and publishes this package, then calls its image build. |

[`docker-cli.yml`](../.github/workflows/docker-cli.yml) also uses `workflow_call`,
but is an internal release step for this product's GHCR image. Its inputs bind
an existing release tag to the approved source SHA. It is not an additional
consumer changelog operation. See [this repository's flow](self-changelog.md)
and [the Docker runtime](docker.md).

## Lower-level actions

[`.github/actions`](../.github/actions/) contains the action interfaces used by
the reusable workflows:

| Action | Responsibility |
| --- | --- |
| [`check`](../.github/actions/check/action.yml) | Invoke contribution validation. |
| [`pr-status`](../.github/actions/pr-status/action.yml) | Produce exact-head PR status and feedback data. |
| [`dependabot`](../.github/actions/dependabot/action.yml) | Author a verified dependency-update fragment. |
| [`version`](../.github/actions/version/action.yml) | Synchronize the reviewable version PR. |
| [`publish`](../.github/actions/publish/action.yml) | Publish verified approved consolidation evidence. |
| [`history`](../.github/actions/history/action.yml) | Prepare reviewed history maintenance. |

These Docker actions share the root `Dockerfile` and call the packaged PHP CLI.
Advanced callers can compose them directly with the required trusted checkout,
permissions and event evidence. The facade supplies that orchestration for
ordinary adoption. [`setup-docker`](../.github/actions/setup-docker/action.yml)
is an internal optional cache helper; it does not implement a changelog operation
and skips persistent self-hosted daemons.

Runtime code and action implementations stay in their current paths. Resources
hold consumer examples and the [published skill](../resources/skills/fast-forward-changelog/SKILL.md),
so packaged instructions remain distinct from GitHub's executable entrypoints.
