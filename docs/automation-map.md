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

Internal files use the `repository-` prefix; public reusable paths retain their
`changelog` names. Display names use `Repository: ...` for internal event
workflows and the image helper, and `Reusable: ...` for public
contracts. These names distinguish ownership without changing consumer paths.

| Repository workflow | Trigger and purpose |
| --- | --- |
| [`repository-ci.yml`](../.github/workflows/repository-ci.yml) | PR, push and manual checks for this package: quality, tests, coverage and installed-runtime fixtures. |
| [`repository-changelog.yml`](../.github/workflows/repository-changelog.yml) | One caller for this package's contribution checks, trusted PR feedback, Dependabot fragments, version preparation and approved-merge publication. |

[`repository-image.yml`](../.github/workflows/repository-image.yml) also uses `workflow_call`,
but is an internal release step for this product's GHCR image. Its inputs bind
an existing release tag to the approved source SHA. It is not an additional
consumer changelog operation. See [this repository's flow](self-changelog.md)
and [the Docker runtime](docker.md).

The internal caller uses the public contribution module for read-only PR checks
and the public lifecycle for trusted events. This keeps write secrets out of the
PR caller and preserves the required `Check Contribution / Check Changelog`
context. A facade caller must permit its nested jobs' maximum permissions even
when an event skips them, so the two event routes are deliberately separate.
After an effective publication, `published-tag` and `published-sha`
connect to the internal image workflow. Preparation and skipped publication do
not start an image publication. The image caller also requires the closed,
merged, same-repository managed PR and a published SHA equal to its merge event.
The four previous internal changelog callers are
replaced together, avoiding competing runs for the same event.

Version and publication retain their shared repository/base lock inside the
public operations. Dependabot and feedback have PR-specific locks; the image
has a tag-specific lock and rechecks its exact source. The caller adds no outer
lock with a nested operation's group: that could make a job wait for its own
workflow. Only the read-only check retains the caller's cancellation group.

Reusable workflows must remain directly in `.github/workflows`; Actions have no
such location restriction. Keeping the six public Action paths in `.github/actions`
preserves existing references and the shared `../../../Dockerfile` build context.
Moving them into `resources/actions` would mix executable adapters with copyable
adoption examples and require a path migration without changing their behavior.

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
[`github-auth`](../.github/actions/github-auth/action.yml) is the internal
authentication helper used only from the trusted runtime. It selects the
default/custom token or creates a repository-scoped App token inside the job;
it does not expose tokens as workflow outputs or alter PR-head permissions.

Runtime code and action implementations stay in their current paths. Resources
hold consumer examples and the [published skill](../resources/skills/fast-forward-changelog/SKILL.md),
so packaged instructions remain distinct from GitHub's executable entrypoints.

GitHub Action downloads and Composer dist can share the same source archive.
Archive rules retain the six public Docker Action metadata files and their root
Docker context, while excluding internal helpers, workflows and maintenance
files. Those small public metadata files are therefore also present in the
Composer dist. `archive.exclude` cannot rewrite a ZIP supplied by GitHub.
See [distribution and runtime verification](docker.md).
