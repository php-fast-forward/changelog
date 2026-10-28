# Changelog in this repository

Use this guide when contributing to Fast Forward Changelog itself. Consumer
repositories should start with [adoption](adoption.md). This package uses the
same fragments and reusable lifecycle it distributes.

## Record a contribution

Read the root [development contract](https://github.com/php-fast-forward/changelog/blob/main/AGENTS.md)
and [canonical skill](https://github.com/php-fast-forward/changelog/blob/main/resources/skills/fast-forward-changelog/SKILL.md).
Use `php bin/changelog <command>` from this source checkout in place of the
skill's CPX package prefix. No installed copy of the skill is needed here.

```sh
composer install
php bin/changelog add "Documents the contribution flow." --category=changed --type=patch
php bin/changelog check
composer check
```

Review and commit only the generated fragment with the implementation.
`add --commit` can commit that one file explicitly; it does not push. After
committing and fetching the intended base, run
`php bin/changelog check --since=origin/main` for the committed contribution.

New changes belong in independent `.changelog/*.md` files. Do not edit central
history, modify inherited fragments or recreate `Unreleased` in an ordinary PR.
Initial development notes were curated before the first publication; their
original content remains in Git. Further editorial curation requires the
verified human maintenance policy described in [policies](policies.md).

## What automation does

| Event | Result |
| --- | --- |
| Contribution PR | Read-only contribution check, exact-head status and sticky feedback from trusted code. |
| Dependabot PR | A verified dependency fragment, followed by validation of the resulting head. |
| Push to `main` | Create or refresh the single `changelog/version` PR. |
| Merge of that version PR | Verify the approved merge and publish its tag/release. Successful publication starts image publication; major-tag promotion consumes a separate release event. |

The [automation map](automation-map.md) lists the repository CI, documentation,
lifecycle and image jobs. The lifecycle uses the public Docker Actions;
consumer dependencies and PR-head PHP never run in privileged jobs.
Version and publication share a concurrency group and do not cancel each other.

This repository enables publication for an approved merged version PR.
Preparing that PR alone creates no release. Maintainers review its exact head,
central history and fragment deletions before merging. Consumer examples start
with `publish: false`; see [the lifecycle](reusable-lifecycle.md).

## Credentials and maintenance

Lifecycle write jobs use `secrets.CHANGELOG_TOKEN` when configured, otherwise
`github.token`. `vars.CHANGELOG_AUTOMATION_ACTOR` selects the exact Bot login
(default `github-actions[bot]`). Repository policy must permit PR creation and
that token's job permissions. The prepared publication-only App route uses `publish-app-client-id` and
`publish-environment`; its activation and secret provisioning remain pending
separate approval. It keeps the managed-PR author unchanged. For automatically
triggered downstream CI, see
[GitHub App authentication](github-app.md).

Dependabot descriptions preserve the live PR title, its supplied versions and
location, then list verified packages. Security alert IDs default to `[]` and
require an independently trusted collector plus live verification; the detailed
[Dependabot policy](policies.md) owns those rules.

Local `status` and `version --dry-run` can preview a release. Applying a version
or history maintenance locally is an explicit maintainer operation, separate
from an ordinary contribution. Follow [transactions and recovery](release-receipts.md)
and [publication](publication.md); never bypass checks to force a stale plan.
For image-only failures after a published release, use [Docker recovery](docker.md).

## Released major tags

The repository channel workflow uses `Actions-R-Us/actions-tagger@v2.0.3` on a
real published stable release event. It maintains `v0` for `0.x` and `v1` for
`1.x`, leaving `v0` on its final `0.x` release. `latest` follows the greatest stable major and advances to `1.x` while `v0` remains unchanged. Consumers
can keep `@v0` without updating a full version pin for each stable release.
Concrete release tags remain immutable; these major tags intentionally move.
`@v0` can cross breaking development minors, unlike Composer/CPX `^0.1`.

Activation is pending publication credentials, tag rules and the environment's
release-tag policy. Publication with the default `GITHUB_TOKEN` does not trigger
the release workflow. The previous PHP updater and manual tag/SHA dispatch are
removed. The Marketplace action does not preserve the previous atomic old-SHA
or ancestry guarantees. See the [operation and activation contract](https://github.com/php-fast-forward/changelog/blob/main/.github/update-release-channels.md)
for the concrete pending configuration, recovery limits and authority boundaries.

---

[Documentation index](index.md)
