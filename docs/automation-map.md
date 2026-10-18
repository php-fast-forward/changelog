# Automation map

A consumer normally copies one [caller example](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/changelog.yml)
and references one reusable: `.github/workflows/changelog.yml`. The files in this
repository have three responsibilities: consumer interfaces, this package's own
event workflows, and implementation actions.

## Consumer entrypoint and optional operations

All seven public reusable paths remain stable:

| Reusable workflow | Responsibility |
| --- | --- |
| [`changelog.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog.yml) | Recommended entrypoint: routes contribution checks, PR feedback, Dependabot fragments, version preparation and optional publication. |
| [`changelog-check.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-check.yml) | Read-only contribution validation at the exact PR head and base. |
| [`changelog-pr-status.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-pr-status.yml) | Trusted exact-head status and one sticky PR comment. |
| [`changelog-dependabot.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-dependabot.yml) | Verified dependency metadata and a deterministic dependency-update fragment. |
| [`changelog-version.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-version.yml) | Create or synchronize the single reviewable version PR. |
| [`changelog-publish.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-publish.yml) | Verify and publish the exact approved consolidation commit. |
| [`changelog-history.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/changelog-history.yml) | Preview or apply backfill/format maintenance on the disposable runner; creates no commit, push or PR. |

The six individual operations are available for advanced composition; ordinary
adoption needs only the entrypoint. The lifecycle does not schedule historical
backfill or formatting automatically. See [the lifecycle guide](reusable-lifecycle.md)
and [operation contracts](github-actions.md).

GitHub requires reusable workflow files directly in `.github/workflows` and
does not support a subdirectory there. Moving their implementation into
`resources` would make the references unusable. See [GitHub's reusable workflow
requirements](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows#creating-a-reusable-workflow).
The [copyable caller](https://github.com/php-fast-forward/changelog/blob/main/resources/workflows/README.md) belongs in `resources`
because it is an adoption example; GitHub executes it only after a consumer
copies it into its own `.github/workflows` and selects a published version or reviewed full commit.

## This repository's workflows

Internal files use the `repository-` prefix; public reusable paths retain their
`changelog` names. Display names use `Repository: ...` for internal event
workflows and the image helper, and `Reusable: ...` for public
contracts. These names distinguish ownership without changing consumer paths.

| Repository workflow | Trigger and purpose |
| --- | --- |
| [`repository-ci.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/repository-ci.yml) | PR, push and manual checks for this package: quality, tests, coverage and installed-runtime fixtures. |
| [`repository-documentation.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/repository-documentation.yml) | Read-only PR, push and manual builds of Markdown guides and the phpDocumentor API; uploads a site artifact without publishing Pages or Wiki. |
| [`repository-impact.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/repository-impact.yml) | Internal read-only helper for tests and documentation: classifies the complete immutable PR diff without hiding required checks. |
| [`repository-changelog.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/repository-changelog.yml) | One caller for this package's contribution checks, trusted PR feedback, Dependabot fragments, version preparation and approved-merge publication. |
| [`repository-release-channels.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/repository-release-channels.yml) | Advance a major branch only to a verified published release; supports explicit recovery with tag and SHA. |

[`repository-image.yml`](https://github.com/php-fast-forward/changelog/blob/main/.github/workflows/repository-image.yml) also uses `workflow_call`,
but is an internal release step for this product's GHCR image. Its inputs bind
an existing release tag to the approved source SHA. It is not an additional
consumer changelog operation. See [this repository's flow](self-changelog.md)
and [the Docker runtime](docker.md).

On a PR, changelog-only and editorial changes keep the required test contexts
visible with a short explanation instead of reinstalling dependencies and running
the package matrix. A version PR receives this treatment only when its actual
paths qualify; its branch name grants no exemption. Guide and asset changes build
documentation. Runtime, shipped resources, configuration, tooling and unclassified
paths run all gates, as do main pushes, manual runs and unavailable comparisons.
The independent contribution check still validates the exact version transaction
and consumed fragments. A failed scope job fails the dependent validation contexts.

Common quality gates and 100% source-line coverage run on Ubuntu. Windows and ARM
retain unit and real integration tests without repeating coverage or static tools.
Windows integration uploads JUnit timings for measured performance investigations.

The internal caller separates read-only PR checks from trusted write events.
Its `Check Contribution / Check Changelog` status is a consumer protection
setting; skipped jobs do not remove the caller's permission requirements.
Only successful publication outputs can start the internal image and release-channel jobs.
Their published SHA must match the approved merged event; preparation alone starts neither.

Version and publication retain their shared repository/base lock inside the
public operations. Dependabot and feedback have PR-specific locks; the image
has a tag-specific lock and rechecks its exact source. The caller adds no outer
lock with a nested operation's group: that could make a job wait for its own
workflow. Only the read-only check retains the caller's cancellation group.
Release channels have a separate repository lock and an atomic old-SHA condition.
See [released branches and recovery](self-changelog.md).

## Parallel maintenance lines

Use separate base and managed branches when maintaining more than one release
line. Each line gets its own version PR and central history:

| Setting | Example 1.x line | Example 2.x line |
| --- | --- | --- |
| Event branch filters and `base-branch` | `1.x` | `2.x` |
| Exclusive `managed-branch` | `changelog/version-1.x` | `changelog/version-2.x` |
| Concrete published baseline | `v1.8.4` | `v2.3.0` |
| Next compatible security patch | `v1.8.5` | `v2.3.1` |

1. Route PR, PR-target and push events to their matching base and managed branch.
   A manual preparation selects that maintenance branch as its workflow ref.
2. Backport the selected fix and a unique validated fragment to each maintained
   line. Keep rendering, history source and PR-reference settings consistent
   between that line's preparation and publication.
3. Review each PR's current/next version, exact history and fragment deletions.
   Publication and review authority remain separate for each line.

A shared managed branch is refused when another base already owns it. Version
and publication serialize per repository/base; distinct locks do not make a
shared managed branch safe. Conflicting ref writes require inspection before retry.

Only tags ancestral to the selected base establish its baseline. Branch names
do not enforce a major range: merging complete 2.x history into 1.x, even with
the `ours` strategy, can make the 2.x tag reachable there. Backport selected
commits and verify the planned major; there is no `expected-major` guard.
Local recovery journals remain scoped to their consumer working tree. Two
lines can share an options fingerprint while their exact base SHA and consumed
hashes still identify different transactions.

Concrete release tags stay immutable. An optional publication channel, if
configured separately, points to an already published commit; it is not a
maintenance branch where fragments accumulate. A channel update must verify
the published commit and progression within that line, not require ancestry
between different major lines or point at an unreviewed maintenance tip.
This guide does not create channels or install a channel workflow in a consumer.

## Lower-level actions

[`.github/actions`](https://github.com/php-fast-forward/changelog/tree/main/.github/actions) contains the action interfaces used by
the reusable workflows:

| Action | Responsibility |
| --- | --- |
| [`check`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/check/action.yml) | Invoke contribution validation. |
| [`pr-status`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/pr-status/action.yml) | Produce exact-head PR status and feedback data. |
| [`dependabot`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/dependabot/action.yml) | Author a verified dependency-update fragment. |
| [`version`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/version/action.yml) | Synchronize the reviewable version PR. |
| [`publish`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/publish/action.yml) | Publish verified approved consolidation evidence. |
| [`history`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/history/action.yml) | Prepare reviewed history maintenance. |

These Docker actions share the root `Dockerfile` and call the packaged PHP CLI.
Advanced callers can compose them directly with the required trusted checkout,
permissions and event evidence. The lifecycle supplies that orchestration for
ordinary adoption. [`setup-docker`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/setup-docker/action.yml)
is an internal optional cache helper; it does not implement a changelog operation
and skips persistent self-hosted daemons.
[`github-auth`](https://github.com/php-fast-forward/changelog/blob/main/.github/actions/github-auth/action.yml) is the internal
authentication helper used only from the trusted runtime. It selects the
default/custom token or creates a repository-scoped App token inside the job;
it does not expose tokens as workflow outputs or alter PR-head permissions.

Runtime code and action implementations stay in their current paths. Resources
hold consumer examples and the [published skill](https://github.com/php-fast-forward/changelog/blob/main/resources/skills/fast-forward-changelog/SKILL.md),
so packaged instructions remain distinct from GitHub's executable entrypoints.

Public Action paths retain their shared Docker build context. GitHub Action
downloads and Composer dist can use the same source archive; see
[archive contents and runtime verification](docker.md) for the inclusion rules.

---

[Documentation index](index.md)
