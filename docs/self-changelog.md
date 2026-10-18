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
| Merge of that version PR | Verify the approved merge and publish its tag/release. Successful publication starts image publication and the released-major branch update. |

The [automation map](automation-map.md) lists the repository CI, documentation,
lifecycle and image jobs. The lifecycle uses the public Docker Actions;
consumer dependencies and PR-head PHP never run in privileged jobs.
Version and publication share a concurrency group and do not cancel each other.

This repository enables publication for an approved merged version PR.
Preparing that PR alone creates no release. Maintainers review its exact head,
central history and fragment deletions before merging. Consumer examples start
with `publish: false`; see [the lifecycle](reusable-lifecycle.md).

## Credentials and maintenance

Write jobs use `secrets.CHANGELOG_TOKEN` when configured, otherwise
`github.token`. `vars.CHANGELOG_AUTOMATION_ACTOR` selects the exact Bot login
(default `github-actions[bot]`). Repository policy must permit PR creation and
that token's job permissions. For automatically triggered downstream CI, see
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

## Released major branches and recovery

After publication, the repository workflow updates the matching `v<major>`
branch, such as `v0`, to the exact published stable release commit. This explicit
call does not wait for a token-generated tag event to start another
workflow. Concrete release tags remain immutable. These channels are distinct
from maintenance branches where fixes and fragments accumulate.

The caller forwards the lifecycle's configured `CHANGELOG_TOKEN`, with
`github.token` as fallback; manual dispatch also uses `CHANGELOG_TOKEN` when
configured. Historical targets with different workflow files may require a
token with Contents and Workflows write access. The default token cannot always
perform this update; no nonexistent YAML `workflows: write` permission is added.

For recovery, open **Repository: Release Channels**, select `main`, and supply
the newest published full tag for that major and its exact 40-character commit
SHA. The updater verifies the release and tag before any write. An existing
channel must identify an older published release of the same major and permit
a fast-forward. Conflicting tags, unrelated branches and stale selectors cannot
overwrite the current ref. See the [operation and recovery contract](https://github.com/php-fast-forward/changelog/blob/main/.github/update-release-channels.md).

Numeric branches appear as development versions in Composer. With Composer's
default stable minimum-stability, constraints select concrete releases.
The automation channel `@v0` can cross breaking
`0.x` minors, whereas Composer/CPX `^0.1` stays below `0.2`. Choose a full tag
or SHA when automatic channel advancement is unsuitable.

---

[Documentation index](index.md)
